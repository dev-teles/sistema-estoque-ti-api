<?php

namespace App\Services;

use App\Exceptions\RegraNegocioException;
use App\Models\Auditoria;
use App\Models\Estoque;
use App\Models\Item;
use App\Models\Movimentacao;
use App\Models\Reserva;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto do sistema de Estoque de TI que altera saldos.
 *
 * Cada operação:
 *  1. abre uma transação (ACID);
 *  2. bloqueia a linha de saldo do item (SELECT ... FOR UPDATE) evitando corridas;
 *  3. valida invariantes: saldo_fisico >= saldo_reservado >= 0;
 *  4. grava o lançamento imutável em `movimentacoes` com o saldo resultante.
 */
class LedgerService
{
    public function entrada(Item $item, int $quantidade, array $extra = []): Movimentacao
    {
        return DB::transaction(fn () => $this->lancar(
            $item, Movimentacao::TIPO_ENTRADA, $quantidade, +$quantidade, 0, $extra
        ));
    }

    public function saida(Item $item, int $quantidade, string $motivo, array $extra = []): Movimentacao
    {
        return DB::transaction(function () use ($item, $quantidade, $motivo, $extra) {
            $this->garantirSemAuditoria($item);

            return $this->lancar(
                $item, Movimentacao::TIPO_SAIDA, $quantidade, -$quantidade, 0,
                ['motivo' => $motivo] + $extra
            );
        });
    }

    public function reservar(Item $item, int $quantidade, string $solicitante, ?string $observacao = null): Reserva
    {
        return DB::transaction(function () use ($item, $quantidade, $solicitante, $observacao) {
            $this->garantirSemAuditoria($item);

            $reserva = Reserva::forceCreate([
                'item_id' => $item->id,
                'user_id' => $this->userId(),
                'solicitante' => $solicitante,
                'quantidade' => $quantidade,
                'status' => Reserva::STATUS_RESERVADO,
                'observacao' => $observacao,
            ]);

            $this->lancar($item, Movimentacao::TIPO_RESERVA, $quantidade, 0, +$quantidade, [
                'reserva_id' => $reserva->id,
                'destino' => $solicitante,
            ]);

            return $reserva;
        });
    }

    /** Entrega física: converte a reserva em saída efetiva. */
    public function confirmarReserva(Reserva $reserva): Reserva
    {
        return DB::transaction(function () use ($reserva) {
            $reserva = Reserva::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            $this->garantirReservaAberta($reserva);
            $item = Item::withTrashed()->findOrFail($reserva->item_id);
            $this->garantirSemAuditoria($item);

            $q = $reserva->quantidade;
            $this->lancar($item, Movimentacao::TIPO_SAIDA, $q, -$q, -$q, [
                'motivo' => 'consumo',
                'reserva_id' => $reserva->id,
                'destino' => $reserva->solicitante,
            ]);

            $reserva->forceFill([
                'status' => Reserva::STATUS_ENTREGUE,
                'finalizada_por' => $this->userId(),
                'finalizada_em' => now(),
            ])->save();

            return $reserva;
        });
    }

    public function cancelarReserva(Reserva $reserva, ?string $justificativa = null): Reserva
    {
        return DB::transaction(function () use ($reserva, $justificativa) {
            $reserva = Reserva::whereKey($reserva->id)->lockForUpdate()->firstOrFail();
            $this->garantirReservaAberta($reserva);
            $item = Item::withTrashed()->findOrFail($reserva->item_id);

            $q = $reserva->quantidade;
            $this->lancar($item, Movimentacao::TIPO_CANCELAMENTO_RESERVA, $q, 0, -$q, [
                'reserva_id' => $reserva->id,
                'justificativa' => $justificativa,
            ]);

            $reserva->forceFill([
                'status' => Reserva::STATUS_CANCELADO,
                'finalizada_por' => $this->userId(),
                'finalizada_em' => now(),
            ])->save();

            return $reserva;
        });
    }

    /** Reverte integralmente uma entrada ou saída anterior. */
    public function estornarMovimentacao(Movimentacao $original, string $justificativa): Movimentacao
    {
        return DB::transaction(function () use ($original, $justificativa) {
            $original = Movimentacao::whereKey($original->id)->lockForUpdate()->firstOrFail();

            if (! in_array($original->tipo, [Movimentacao::TIPO_ENTRADA, Movimentacao::TIPO_SAIDA], true)) {
                throw new RegraNegocioException('Apenas movimentações de entrada ou saída podem ser estornadas.');
            }
            if (Movimentacao::where('movimentacao_estornada_id', $original->id)->exists()) {
                throw new RegraNegocioException('Esta movimentação já foi estornada.');
            }

            $item = Item::withTrashed()->findOrFail($original->item_id);
            $this->garantirSemAuditoria($item);

            return $this->lancar(
                $item, Movimentacao::TIPO_ESTORNO, $original->quantidade, -$original->delta_fisico, 0,
                ['movimentacao_estornada_id' => $original->id, 'justificativa' => $justificativa]
            );
        });
    }

    /** Ajuste gerado pela reconciliação do inventário cíclico (ignora o bloqueio da própria auditoria). */
    public function ajusteAuditoria(Item $item, int $delta, Auditoria $auditoria): Movimentacao
    {
        return DB::transaction(fn () => $this->lancar(
            $item, Movimentacao::TIPO_AJUSTE_AUDITORIA, abs($delta), $delta, 0, [
                'auditoria_id' => $auditoria->id,
                'justificativa' => "Reconciliação da auditoria #{$auditoria->id}",
            ]
        ));
    }

    /** Bloqueia o saldo do item para atualização dentro da transação corrente. */
    public function bloquearSaldo(Item $item): Estoque
    {
        return Estoque::where('item_id', $item->id)->lockForUpdate()->first()
            ?? Estoque::forceCreate(['item_id' => $item->id, 'saldo_fisico' => 0, 'saldo_reservado' => 0]);
    }

    private function lancar(Item $item, string $tipo, int $quantidade, int $deltaFisico, int $deltaReservado, array $extra = []): Movimentacao
    {
        if ($quantidade <= 0) {
            throw new RegraNegocioException('A quantidade deve ser maior que zero.');
        }

        $estoque = $this->bloquearSaldo($item);

        $novoFisico = $estoque->saldo_fisico + $deltaFisico;
        $novoReservado = $estoque->saldo_reservado + $deltaReservado;

        if ($novoFisico < 0 || $novoReservado < 0 || $novoReservado > $novoFisico) {
            throw new RegraNegocioException(
                "Saldo insuficiente para \"{$item->nome}\". Disponível: {$estoque->saldo_disponivel}, físico: {$estoque->saldo_fisico}.",
                422,
                ['saldo_fisico' => $estoque->saldo_fisico, 'saldo_disponivel' => $estoque->saldo_disponivel]
            );
        }

        $estoque->forceFill(['saldo_fisico' => $novoFisico, 'saldo_reservado' => $novoReservado])->save();

        return Movimentacao::forceCreate(array_merge([
            'item_id' => $item->id,
            'user_id' => $this->userId(),
            'tipo' => $tipo,
            'quantidade' => $quantidade,
            'delta_fisico' => $deltaFisico,
            'delta_reservado' => $deltaReservado,
            'saldo_fisico_apos' => $novoFisico,
            'saldo_reservado_apos' => $novoReservado,
        ], $extra));
    }

    private function garantirSemAuditoria(Item $item): void
    {
        $emCurso = Auditoria::where('categoria_id', $item->categoria_id)
            ->where('status', Auditoria::STATUS_EM_ANDAMENTO)
            ->exists();

        if ($emCurso) {
            throw new RegraNegocioException(
                'Categoria em auditoria (inventário cíclico). Saídas e reservas estão temporariamente bloqueadas.',
                423
            );
        }
    }

    private function garantirReservaAberta(Reserva $reserva): void
    {
        if ($reserva->status !== Reserva::STATUS_RESERVADO) {
            throw new RegraNegocioException("Reserva já se encontra com status \"{$reserva->status}\".");
        }
    }

    private function userId(): int
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new RegraNegocioException('Utilizador não autenticado.', 401);
        }

        return $user->id;
    }
}
