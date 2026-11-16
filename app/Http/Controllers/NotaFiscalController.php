<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotaFiscalResource;
use App\Models\Item;
use App\Models\NotaFiscal;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NotaFiscalController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = NotaFiscal::with(['fornecedor', 'user'])->withCount('movimentacoes')->latest('id');

        if ($busca = $request->query('search')) {
            $query->where('numero', 'like', "%{$busca}%");
        }
        if ($fornecedor = $request->query('fornecedor_id')) {
            $query->where('fornecedor_id', $fornecedor);
        }

        return NotaFiscalResource::collection($query->paginate($this->perPage($request)));
    }

    public function show(NotaFiscal $notaFiscal): NotaFiscalResource
    {
        return new NotaFiscalResource($notaFiscal->load(['fornecedor', 'user', 'movimentacoes.item', 'movimentacoes.user']));
    }

    /**
     * Regista a NF e (opcionalmente) as entradas dos itens recebidos — tudo numa única transação.
     */
    public function store(Request $request): NotaFiscalResource
    {
        $dados = $request->validate([
            'fornecedor_id' => ['required', 'exists:fornecedores,id'],
            'numero' => ['required', 'string', 'max:30',
                Rule::unique('notas_fiscais')->where('fornecedor_id', $request->input('fornecedor_id'))
                    ->where('serie', (string) $request->input('serie', ''))],
            'serie' => ['nullable', 'string', 'max:10'],
            'data_emissao' => ['required', 'date', 'before_or_equal:today'],
            'valor_total' => ['nullable', 'numeric', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:255'],
            'itens' => ['nullable', 'array', 'max:200'],
            'itens.*.item_id' => ['required', 'distinct', $this->existe('itens', true)],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
            'itens.*.valor_unitario' => ['nullable', 'numeric', 'min:0'],
        ], [
            'numero.unique' => 'Já existe uma nota fiscal com este número para o fornecedor.',
            'itens.*.item_id.distinct' => 'O mesmo item foi informado mais de uma vez.',
        ]);

        $nota = DB::transaction(function () use ($dados, $request) {
            $nota = NotaFiscal::create([
                'fornecedor_id' => $dados['fornecedor_id'],
                'user_id' => $request->user()->id,
                'numero' => $dados['numero'],
                'serie' => $dados['serie'] ?? '',
                'data_emissao' => $dados['data_emissao'],
                'valor_total' => $dados['valor_total'] ?? null,
                'observacao' => $dados['observacao'] ?? null,
            ]);

            foreach ($dados['itens'] ?? [] as $linha) {
                $this->ledger->entrada(Item::findOrFail($linha['item_id']), (int) $linha['quantidade'], [
                    'nota_fiscal_id' => $nota->id,
                    'valor_unitario' => $linha['valor_unitario'] ?? null,
                ]);
            }

            return $nota;
        });

        return new NotaFiscalResource($nota->load(['fornecedor', 'user', 'movimentacoes.item']));
    }
}
