<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Reserva */
class ReservaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'nome' => $this->item->nome,
                'unidade_medida' => $this->item->unidade_medida,
            ]),
            'item_id' => $this->item_id,
            'solicitante' => $this->solicitante,
            'quantidade' => $this->quantidade,
            'status' => $this->status,
            'observacao' => $this->observacao,
            'criado_por' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'finalizada_por' => $this->whenLoaded('finalizador', fn () => $this->finalizador
                ? ['id' => $this->finalizador->id, 'name' => $this->finalizador->name] : null),
            'finalizada_em' => $this->finalizada_em?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
