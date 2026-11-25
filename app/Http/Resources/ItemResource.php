<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Item  — corresponde à interface `ItemCatalogo` no frontend. */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'numero_serie' => $this->numero_serie,
            'descricao' => $this->descricao,
            'categoria_id' => $this->categoria_id,
            'categoria' => $this->whenLoaded('categoria', fn () => [
                'id' => $this->categoria->id,
                'nome' => $this->categoria->nome,
            ]),
            'unidade_medida' => $this->unidade_medida,
            'quantidade_minima' => $this->quantidade_minima,
            'lead_time_dias' => $this->lead_time_dias,
            'ativo' => $this->deleted_at === null,
            'saldo_fisico' => $this->whenLoaded('estoque', fn () => $this->estoque?->saldo_fisico ?? 0),
            'saldo_disponivel' => $this->whenLoaded('estoque', fn () => $this->estoque?->saldo_disponivel ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
