<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auditoria extends Model
{
    public const STATUS_EM_ANDAMENTO = 'em_andamento';
    public const STATUS_FINALIZADA = 'finalizada';

    protected $fillable = [
        'categoria_id', 'status', 'iniciada_por',
        'finalizada_por', 'finalizada_em', 'observacao',
    ];

    protected $casts = ['finalizada_em' => 'datetime'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function iniciador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciada_por');
    }

    public function finalizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizada_por');
    }

    public function contagens(): HasMany
    {
        return $this->hasMany(AuditoriaContagem::class);
    }

    public function emAndamento(): bool
    {
        return $this->status === self::STATUS_EM_ANDAMENTO;
    }
}
