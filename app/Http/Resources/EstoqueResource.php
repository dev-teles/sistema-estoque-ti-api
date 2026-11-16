<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Estoque — corresponde à interface `UnidadeEstoque` no frontend. */
class EstoqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => new ItemResource($this->item),
            'saldo_fisico' => $this->saldo_fisico,
            'saldo_reservado' => $this->saldo_reservado,
            'saldo_disponivel' => $this->saldo_disponivel,
            'status_reposicao' => $this->statusReposicao($this->item->quantidade_minima),
            'atualizado_em' => $this->updated_at?->toIso8601String(),
        ];
    }
}
