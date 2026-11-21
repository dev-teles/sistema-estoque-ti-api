<?php

namespace App\Models;

use App\Casts\EncryptedAttribute;
use App\Models\Concerns\HasRowLevelSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reserva extends Model
{
    use HasRowLevelSecurity;

    public const STATUS_RESERVADO = 'reservado';
    public const STATUS_ENTREGUE = 'entregue';
    public const STATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'item_id', 'solicitante', 'quantidade', 'observacao',
    ];

    protected $casts = [
        'quantidade' => 'integer',
        'observacao' => EncryptedAttribute::class,
        'finalizada_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Reserva $reserva) {
            if ($user = static::rlsUser()) {
                if ($reserva->user_id && (int) $reserva->user_id !== (int) $user->id) {
                    throw new AuthorizationException('RLS: user_id da reserva deve pertencer ao utilizador autenticado.');
                }
                $reserva->user_id = $user->id;
            }
        });
    }

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
