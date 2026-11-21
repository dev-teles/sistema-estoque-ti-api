<?php

namespace App\Http\Controllers;

use App\Http\Resources\MovimentacaoResource;
use App\Models\Movimentacao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Livro-razão: somente leitura (não existem rotas PUT/DELETE). */
class MovimentacaoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'item_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'reserva_id' => ['nullable', 'integer', 'min:1'],
            'nota_fiscal_id' => ['nullable', 'integer', 'min:1'],
            'auditoria_id' => ['nullable', 'integer', 'min:1'],
            'tipo' => ['nullable', Rule::in(Movimentacao::TIPOS)],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
        ]);

        $query = Movimentacao::with(['item', 'user', 'notaFiscal'])
            ->withCount('estornos')
            ->orderByDesc('id');

        foreach (['item_id', 'user_id', 'tipo', 'reserva_id', 'nota_fiscal_id', 'auditoria_id'] as $filtro) {
            if ($valor = $request->query($filtro)) {
                $query->where($filtro, $valor);
            }
        }
        if ($inicio = $request->query('data_inicio')) {
            $query->where('created_at', '>=', $inicio.' 00:00:00');
        }
        if ($fim = $request->query('data_fim')) {
            $query->where('created_at', '<=', $fim.' 23:59:59');
        }

        return MovimentacaoResource::collection($query->paginate($this->perPage($request, 25)));
    }
}
