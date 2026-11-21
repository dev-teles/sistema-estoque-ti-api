<?php

namespace App\Http\Controllers;

use App\Exceptions\RegraNegocioException;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Catálogo de itens de TI (o "dicionário" do estoque). */
class CatalogoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Item::with(['categoria', 'estoque'])->orderBy('nome');

        if ($request->boolean('incluir_inativos')) {
            $query->withTrashed();
        }
        if ($busca = $request->query('search')) {
            $query->where('nome', 'like', $this->termoLike((string) $busca));
        }
        if ($categoria = $request->query('categoria_id')) {
            $query->where('categoria_id', (int) $categoria);
        }

        return ItemResource::collection($query->paginate($this->perPage($request)));
    }

    public function show(int $id): ItemResource
    {
        return new ItemResource(Item::withTrashed()->with(['categoria', 'estoque'])->findOrFail($id));
    }

    public function store(Request $request): ItemResource
    {
        $dados = $this->validar($request);

        $item = Item::create($dados)->refresh();

        return new ItemResource($item->load(['categoria', 'estoque']));
    }

    public function update(Request $request, Item $item): ItemResource
    {
        $item->update($this->validar($request, parcial: true));

        return new ItemResource($item->load(['categoria', 'estoque']));
    }

    /** Soft delete (inativação). O histórico permanece intacto. */
    public function destroy(Item $item): JsonResponse
    {
        if (($item->loadMissing('estoque')->estoque?->saldo_reservado ?? 0) > 0) {
            throw new RegraNegocioException('Não é possível inativar um item com reservas pendentes.');
        }

        $item->delete();

        return response()->json(['message' => 'Item inativado.']);
    }

    public function restore(int $id): ItemResource
    {
        $item = Item::onlyTrashed()->findOrFail($id);
        $item->restore();

        return new ItemResource($item->load(['categoria', 'estoque']));
    }

    private function validar(Request $request, bool $parcial = false): array
    {
        $req = $parcial ? 'sometimes' : 'required';

        $d = $request->validate([
            'nome' => [$req, 'string', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'categoria_id' => [$req, $this->existe('categorias')],
            'unidade_medida' => ['sometimes', 'string', 'max:20'],
            'quantidade_minima' => [$req, 'integer', 'min:0'],
            'lead_time_dias' => ['sometimes', 'integer', 'min:0', 'max:365'],
            // Aceita também `lead_time` (nome usado na documentação da API).
            'lead_time' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        if (isset($d['lead_time']) && ! isset($d['lead_time_dias'])) {
            $d['lead_time_dias'] = $d['lead_time'];
        }
        unset($d['lead_time']);

        return $d;
    }
}
