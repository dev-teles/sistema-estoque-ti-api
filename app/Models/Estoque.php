<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo atual de um item de TI (UnidadeEstoque no frontend).
 * Nunca deve ser alterado diretamente: use App\Services\LedgerService.
 */
class Estoque extends Model
{
    public const STATUS_REGULAR = 'regular';
    public const STATUS_ALERTA = 'alerta';
    public const STATUS_CRITICO = 'critico';

    protected $fillable = ['item_id', 'saldo_fisico', 'saldo_reservado'];

    protected $casts = [
        'saldo_fisico' => 'integer',
        'saldo_reservado' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function getSaldoDisponivelAttribute(): int
    {
        return $this->saldo_fisico - $this->saldo_reservado;
    }

    public function statusReposicao(int $quantidadeMinima): string
    {
        $disponivel = $this->saldo_disponivel;

        return match (true) {
            $disponivel <= 0 => self::STATUS_CRITICO,
            $disponivel <= $quantidadeMinima => self::STATUS_ALERTA,
            default => self::STATUS_REGULAR,
        };
    }

    /** Expressão SQL equivalente a statusReposicao() (requer join com `itens`). */
    public static function statusSql(): string
    {
        $disp = '(CAST(estoques.saldo_fisico AS SIGNED) - CAST(estoques.saldo_reservado AS SIGNED))';

        return "CASE WHEN {$disp} <= 0 THEN 'critico' WHEN {$disp} <= itens.quantidade_minima THEN 'alerta' ELSE 'regular' END";
    }

    /** Estoques de itens ativos de TI, com join em `itens`. */
    public function scopeAtivos(Builder $query): Builder
    {
        return $query->join('itens', 'itens.id', '=', 'estoques.item_id')
            ->whereNull('itens.deleted_at')
            ->select('estoques.*');
    }
}
