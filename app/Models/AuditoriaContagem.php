<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditoriaContagem extends Model
{
    protected $table = 'auditoria_contagens';

    protected $fillable = [
        'auditoria_id', 'item_id', 'user_id', 'quantidade_contada', 'saldo_sistema', 'diferenca',
    ];

    protected $casts = [
        'quantidade_contada' => 'integer',
        'saldo_sistema' => 'integer',
        'diferenca' => 'integer',
    ];

    public function auditoria(): BelongsTo
    {
        return $this->belongsTo(Auditoria::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
