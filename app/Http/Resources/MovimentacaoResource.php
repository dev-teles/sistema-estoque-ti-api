<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Movimentacao */
class MovimentacaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'motivo' => $this->motivo,
            'quantidade' => $this->quantidade,
            'delta_fisico' => $this->delta_fisico,
            'delta_reservado' => $this->delta_reservado,
            'saldo_fisico_apos' => $this->saldo_fisico_apos,
            'saldo_reservado_apos' => $this->saldo_reservado_apos,
            'valor_unitario' => $this->valor_unitario,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'nome' => $this->item->nome,
                'unidade_medida' => $this->item->unidade_medida,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'nota_fiscal' => $this->whenLoaded('notaFiscal', fn () => $this->notaFiscal ? [
                'id' => $this->notaFiscal->id,
                'numero' => $this->notaFiscal->numero,
            ] : null),
            'reserva_id' => $this->reserva_id,
            'auditoria_id' => $this->auditoria_id,
            'movimentacao_estornada_id' => $this->movimentacao_estornada_id,
            'estornada' => $this->when(isset($this->estornos_count), fn () => $this->estornos_count > 0),
            'destino' => $this->destino,
            'justificativa' => $this->justificativa,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
