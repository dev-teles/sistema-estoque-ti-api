<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservaResource;
use App\Models\Item;
use App\Models\Reserva;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Fluxo de balcão de TI: reservas → entrega (saída) ou cancelamento. */
class ReservaController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    /** Por padrão lista apenas as pendentes; ?status=todas|entregue|cancelado para o histórico. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', Rule::in(['reservado', 'entregue', 'cancelado', 'todas'])]]);

        $status = $request->query('status', Reserva::STATUS_RESERVADO);
        $query = Reserva::with(['item', 'user', 'finalizador']);

        if ($status !== 'todas') {
            $query->where('status', $status);
        }
        if ($busca = $request->query('search')) {
            $like = $this->termoLike((string) $busca);
            $query->where(fn ($q) => $q->where('solicitante', 'like', $like)
                ->orWhereHas('item', fn ($i) => $i->where('nome', 'like', $like)));
        }

        $status === Reserva::STATUS_RESERVADO ? $query->oldest('id') : $query->latest('id');

        return ReservaResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): ReservaResource
    {
        $dados = $request->validate([
            'item_id' => ['required', $this->existe('itens', true)],
            'quantidade' => ['required', 'integer', 'min:1', 'max:1000000'],
            'solicitante' => ['required', 'string', 'max:150'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $reserva = $this->ledger->reservar(
            Item::findOrFail($dados['item_id']), (int) $dados['quantidade'],
            $dados['solicitante'], $dados['observacao'] ?? null
        );

        return new ReservaResource($reserva->load(['item', 'user']));
    }

    public function confirmar(Reserva $reserva): ReservaResource
    {
        return new ReservaResource($this->ledger->confirmarReserva($reserva)->load(['item', 'user', 'finalizador']));
    }

    public function cancelar(Request $request, Reserva $reserva): ReservaResource
    {
        $dados = $request->validate(['justificativa' => ['nullable', 'string', 'max:500']]);

        return new ReservaResource(
            $this->ledger->cancelarReserva($reserva, $dados['justificativa'] ?? null)->load(['item', 'user', 'finalizador'])
        );
    }
}
