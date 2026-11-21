<?php

namespace App\Models;

use App\Casts\EncryptedAttribute;
use App\Models\Concerns\HasRowLevelSecurity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotaFiscal extends Model
{
    use HasRowLevelSecurity;

    protected $table = 'notas_fiscais';

    protected $fillable = [
        'fornecedor_id', 'numero', 'serie',
        'data_emissao', 'valor_total', 'observacao',
    ];

    protected $casts = [
        'data_emissao' => 'date:Y-m-d',
        'valor_total' => 'decimal:2',
        'observacao' => EncryptedAttribute::class,
    ];

    protected static function booted(): void
    {
        static::creating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::updating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::deleting(fn () => static::rlsRequireRole('admin', 'gestor'));
    }

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
