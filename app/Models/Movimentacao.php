<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Lançamento do livro-razão do Estoque de TI. APPEND-ONLY: qualquer tentativa de update/delete
 * é bloqueada aqui (camada aplicação) e por triggers no MySQL (camada base de dados).
 */
class Movimentacao extends Model
{
    public const UPDATED_AT = null;

    public const TIPO_ENTRADA = 'entrada';
    public const TIPO_SAIDA = 'saida';
    public const TIPO_RESERVA = 'reserva';
    public const TIPO_CANCELAMENTO_RESERVA = 'cancelamento_reserva';
    public const TIPO_ESTORNO = 'estorno';
    public const TIPO_AJUSTE_AUDITORIA = 'ajuste_auditoria';

    public const TIPOS = [
        self::TIPO_ENTRADA, self::TIPO_SAIDA, self::TIPO_RESERVA,
        self::TIPO_CANCELAMENTO_RESERVA, self::TIPO_ESTORNO, self::TIPO_AJUSTE_AUDITORIA,
    ];

    protected $table = 'movimentacoes';

    protected $fillable = [
        'item_id', 'user_id', 'tipo', 'motivo', 'quantidade',
        'delta_fisico', 'delta_reservado', 'saldo_fisico_apos', 'saldo_reservado_apos',
        'valor_unitario', 'nota_fiscal_id', 'reserva_id', 'auditoria_id',
        'movimentacao_estornada_id', 'destino', 'justificativa', 'created_at',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'delta_fisico' => 'integer',
        'delta_reservado' => 'integer',
        'saldo_fisico_apos' => 'integer',
        'saldo_reservado_apos' => 'integer',
        'valor_unitario' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Livro-razão imutável: movimentações não podem ser alteradas.'));
        static::deleting(fn () => throw new LogicException('Livro-razão imutável: movimentações não podem ser excluídas.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notaFiscal(): BelongsTo
    {
        return $this->belongsTo(NotaFiscal::class);
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class);
    }

    public function estornada(): BelongsTo
    {
        return $this->belongsTo(Movimentacao::class, 'movimentacao_estornada_id');
    }

    /** Estorno que reverteu esta movimentação (no máximo um — unique key). */
    public function estornos(): HasMany
    {
        return $this->hasMany(Movimentacao::class, 'movimentacao_estornada_id');
    }
}
