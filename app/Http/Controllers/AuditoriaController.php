<?php

namespace App\Http\Controllers;

use App\Exceptions\RegraNegocioException;
use App\Models\Auditoria;
use App\Models\AuditoriaContagem;
use App\Models\Item;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Inventário cíclico de TI: iniciar (bloqueia saídas da categoria) → contagem cega → reconciliação → finalizar.
 */
class AuditoriaController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Auditoria::STATUS_EM_ANDAMENTO, Auditoria::STATUS_FINALIZADA])],
        ]);

        $query = Auditoria::with(['categoria:id,nome', 'iniciador:id,name', 'finalizador:id,name'])
            ->withCount('contagens')
            ->latest('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return JsonResource::collection($query->paginate($this->perPage($request)))->response();
    }

    /** Detalhe para a tela de contagem. Contagem cega: o saldo do sistema NÃO é exposto enquanto em andamento. */
    public function show(Auditoria $auditoria): JsonResponse
    {
        $auditoria->load(['categoria:id,nome', 'iniciador:id,name', 'finalizador:id,name']);
        $contagens = $auditoria->contagens()->with('user:id,name')->get()->keyBy('item_id');

        $itens = Item::withTrashed()
            ->where('categoria_id', $auditoria->categoria_id)
            ->when($auditoria->emAndamento(), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('nome')
            ->get(['id', 'nome', 'unidade_medida'])
            ->map(function (Item $item) use ($contagens, $auditoria) {
                $c = $contagens->get($item->id);
                $linha = [
                    'item_id' => $item->id,
                    'nome' => $item->nome,
                    'unidade_medida' => $item->unidade_medida,
                    'quantidade_contada' => $c?->quantidade_contada,
                    'contado_por' => $c?->user?->name,
                ];
                if (! $auditoria->emAndamento()) {
                    $linha['saldo_sistema'] = $c?->saldo_sistema;
                    $linha['diferenca'] = $c?->diferenca;
                }

                return $linha;
            });

        return response()->json(['data' => $auditoria->toArray() + ['itens' => $itens]]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'categoria_id' => ['required', $this->existe('categorias')],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $auditoria = DB::transaction(function () use ($dados, $request) {
            $existe = Auditoria::where('categoria_id', $dados['categoria_id'])
                ->where('status', Auditoria::STATUS_EM_ANDAMENTO)->lockForUpdate()->exists();
            if ($existe) {
                throw new RegraNegocioException('Já existe uma auditoria em andamento para esta categoria.');
            }

            return Auditoria::forceCreate($dados + [
                'iniciada_por' => $request->user()->id,
                'status' => Auditoria::STATUS_EM_ANDAMENTO,
            ]);
        });

        return response()->json(['data' => $auditoria->load('categoria:id,nome')], 201);
    }

    /** Recebe/atualiza contagens: { contagens: [{ item_id, quantidade_contada }] } */
    public function contagem(Request $request, Auditoria $auditoria): JsonResponse
    {
        $this->garantirEmAndamento($auditoria);

        $dados = $request->validate([
            'contagens' => ['required', 'array', 'min:1'],
            'contagens.*.item_id' => ['required', 'distinct',
                Rule::exists('itens', 'id')->where('categoria_id', $auditoria->categoria_id)->whereNull('deleted_at')],
            'contagens.*.quantidade_contada' => ['required', 'integer', 'min:0', 'max:1000000'],
        ], ['contagens.*.item_id.exists' => 'Item não pertence à categoria auditada.']);

        foreach ($dados['contagens'] as $linha) {
            $contagem = AuditoriaContagem::firstOrNew([
                'auditoria_id' => $auditoria->id,
                'item_id' => $linha['item_id'],
            ]);
            $contagem->quantidade_contada = $linha['quantidade_contada'];
            $contagem->user_id = $request->user()->id;
            $contagem->save();
        }

        return response()->json(['message' => 'Contagem registada.', 'total_contado' => $auditoria->contagens()->count()]);
    }

    /** Pré-visualização das divergências para aprovação do gestor. */
    public function reconciliacao(Auditoria $auditoria): JsonResponse
    {
        $contagens = $auditoria->contagens()->get()->keyBy('item_id');

        $linhas = Item::with('estoque')
            ->where('categoria_id', $auditoria->categoria_id)
            ->orderBy('nome')->get()
            ->map(function (Item $item) use ($contagens) {
                $c = $contagens->get($item->id);
                $sistema = $item->estoque?->saldo_fisico ?? 0;

                return [
                    'item_id' => $item->id,
                    'nome' => $item->nome,
                    'unidade_medida' => $item->unidade_medida,
                    'saldo_sistema' => $sistema,
                    'saldo_reservado' => $item->estoque?->saldo_reservado ?? 0,
                    'quantidade_contada' => $c?->quantidade_contada,
                    'diferenca' => $c ? $c->quantidade_contada - $sistema : null,
                ];
            });

        return response()->json(['data' => [
            'auditoria' => $auditoria->load('categoria:id,nome'),
            'itens' => $linhas,
            'resumo' => [
                'itens_categoria' => $linhas->count(),
                'itens_contados' => $linhas->whereNotNull('quantidade_contada')->count(),
                'itens_divergentes' => $linhas->filter(fn ($l) => ($l['diferenca'] ?? 0) !== 0)->count(),
            ],
        ]]);
    }

    /** Compara contado × sistema e gera os lançamentos `ajuste_auditoria` automaticamente. */
    public function finalizar(Request $request, Auditoria $auditoria): JsonResponse
    {
        $this->garantirEmAndamento($auditoria);

        $ajustes = DB::transaction(function () use ($auditoria, $request) {
            $auditoria = Auditoria::whereKey($auditoria->id)->lockForUpdate()->firstOrFail();
            $this->garantirEmAndamento($auditoria);

            $ajustes = [];
            foreach ($auditoria->contagens()->get() as $contagem) {
                $item = Item::withTrashed()->findOrFail($contagem->item_id);
                $estoque = $this->ledger->bloquearSaldo($item);
                $diferenca = $contagem->quantidade_contada - $estoque->saldo_fisico;

                if ($contagem->quantidade_contada < $estoque->saldo_reservado) {
                    throw new RegraNegocioException(
                        "\"{$item->nome}\": contagem ({$contagem->quantidade_contada}) inferior ao reservado ({$estoque->saldo_reservado}). Cancele reservas antes de finalizar."
                    );
                }

                $contagem->forceFill(['saldo_sistema' => $estoque->saldo_fisico, 'diferenca' => $diferenca])->save();

                if ($diferenca !== 0) {
                    $this->ledger->ajusteAuditoria($item, $diferenca, $auditoria);
                    $ajustes[] = ['item_id' => $item->id, 'nome' => $item->nome, 'diferenca' => $diferenca];
                }
            }

            $auditoria->forceFill([
                'status' => Auditoria::STATUS_FINALIZADA,
                'finalizada_por' => $request->user()->id,
                'finalizada_em' => now(),
            ])->save();

            return $ajustes;
        });

        return response()->json([
            'message' => 'Auditoria finalizada. '.count($ajustes).' ajuste(s) gerado(s).',
            'ajustes' => $ajustes,
        ]);
    }

    private function garantirEmAndamento(Auditoria $auditoria): void
    {
        if (! $auditoria->emAndamento()) {
            throw new RegraNegocioException('Esta auditoria já foi finalizada.');
        }
    }
}
