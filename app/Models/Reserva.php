<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reserva extends Model
{
    public const STATUS_RESERVADO = 'reservado';
    public const STATUS_ENTREGUE = 'entregue';
    public const STATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'item_id', 'user_id', 'solicitante', 'quantidade',
        'status', 'observacao', 'finalizada_por', 'finalizada_em',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'finalizada_em' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function finalizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizada_por');
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(Movimentacao::class);
    }
}
