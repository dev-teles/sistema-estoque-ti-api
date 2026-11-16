<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotaFiscal extends Model
{
    protected $table = 'notas_fiscais';

    protected $fillable = [
        'fornecedor_id', 'user_id', 'numero', 'serie',
        'data_emissao', 'valor_total', 'observacao',
    ];

    protected $casts = [
        'data_emissao' => 'date:Y-m-d',
        'valor_total' => 'decimal:2',
    ];

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(Movimentacao::class);
    }
}
