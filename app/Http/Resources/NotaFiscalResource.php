<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\NotaFiscal */
class NotaFiscalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'serie' => $this->serie,
            'data_emissao' => $this->data_emissao?->format('Y-m-d'),
            'valor_total' => $this->valor_total,
            'observacao' => $this->observacao,
            'fornecedor' => $this->whenLoaded('fornecedor', fn () => [
                'id' => $this->fornecedor->id,
                'nome' => $this->fornecedor->nome,
                'cnpj' => $this->fornecedor->cnpj,
            ]),
            'registrada_por' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'itens_count' => $this->whenCounted('movimentacoes'),
            'movimentacoes' => MovimentacaoResource::collection($this->whenLoaded('movimentacoes')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
