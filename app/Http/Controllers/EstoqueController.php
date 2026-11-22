<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstoqueResource;
use App\Http\Resources\MovimentacaoResource;
use App\Models\Estoque;
use App\Models\Item;
use App\Models\Movimentacao;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Estoque físico de TI e lançamentos operacionais (entrada, saída, estorno). */
class EstoqueController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    /** Inventário atual paginado: ?search=&categoria_id=&status=regular|alerta|critico&sort=nome|saldo|status */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in([Estoque::STATUS_REGULAR, Estoque::STATUS_ALERTA, Estoque::STATUS_CRITICO])],
            'sort' => ['nullable', Rule::in(['nome', 'saldo', 'status'])],
        ]);

        $query = Estoque::ativos()->with('item.categoria');

        if ($busca = $request->query('search')) {
            $query->where('itens.nome', 'like', $this->termoLike((string) $busca));
        }
        if ($categoria = $request->query('categoria_id')) {
            $query->where('itens.categoria_id', (int) $categoria);
        }
        if ($status = $request->query('status')) {
            $query->whereRaw(Estoque::statusSql().' = ?', [$status]);
        }

        match ($request->query('sort', 'nome')) {
            'saldo' => $query->orderByRaw('(CAST(estoques.saldo_fisico AS SIGNED) - CAST(estoques.saldo_reservado AS SIGNED)) asc'),
            'status' => $query->orderByRaw("CASE ".Estoque::statusSql()." WHEN 'critico' THEN 0 WHEN 'alerta' THEN 1 ELSE 2 END"),
            default => $query->orderBy('itens.nome'),
        };

        return EstoqueResource::collection($query->paginate($this->perPage($request)));
    }

    public function entrada(Request $request): MovimentacaoResource
    {
        $dados = $request->validate([
            'item_id' => ['required', $this->existe('itens', true)],
            'quantidade' => ['required', 'integer', 'min:1', 'max:1000000'],
            'nota_fiscal_id' => ['nullable', $this->existe('notas_fiscais')],
            'valor_unitario' => ['nullable', 'numeric', 'min:0'],
            'justificativa' => ['nullable', 'string', 'max:500'],
        ]);

        $mov = $this->ledger->entrada(Item::findOrFail($dados['item_id']), (int) $dados['quantidade'], [
            'nota_fiscal_id' => $dados['nota_fiscal_id'] ?? null,
            'valor_unitario' => $dados['valor_unitario'] ?? null,
            'justificativa' => $dados['justificativa'] ?? null,
        ]);

        return new MovimentacaoResource($mov->load(['item', 'user']));
    }

    public function saida(Request $request): MovimentacaoResource
    {
        $dados = $request->validate([
            'item_id' => ['required', $this->existe('itens', true)],
            'quantidade' => ['required', 'integer', 'min:1', 'max:1000000'],
            'motivo' => ['required', Rule::in(['consumo', 'transferencia', 'dano'])],
            'destino' => ['nullable', 'required_if:motivo,transferencia', 'string', 'max:150'],
            'justificativa' => ['nullable', 'required_if:motivo,dano', 'string', 'max:500'],
        ]);

        $mov = $this->ledger->saida(Item::findOrFail($dados['item_id']), (int) $dados['quantidade'], $dados['motivo'], [
            'destino' => $dados['destino'] ?? null,
            'justificativa' => $dados['justificativa'] ?? null,
        ]);

        return new MovimentacaoResource($mov->load(['item', 'user']));
    }

    /** Estorno justificado: reverte integralmente uma entrada ou saída anterior. */
    public function estorno(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'movimentacao_id' => ['required', $this->existe('movimentacoes')],
            'justificativa' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $mov = $this->ledger->estornarMovimentacao(
            Movimentacao::findOrFail($dados['movimentacao_id']),
            $dados['justificativa']
        );

        return (new MovimentacaoResource($mov->load(['item', 'user'])))->response()->setStatusCode(201);
    }
}
