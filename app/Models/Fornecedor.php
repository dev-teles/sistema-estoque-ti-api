<?php

namespace App\Models;

use App\Models\Concerns\HasRowLevelSecurity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fornecedores de equipamentos, peças e insumos de TI. */
class Fornecedor extends Model
{
    use HasRowLevelSecurity;

    protected $table = 'fornecedores';

    protected $fillable = ['nome', 'cnpj', 'contato_nome', 'email', 'telefone'];

    protected static function booted(): void
    {
        static::creating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::updating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::deleting(fn () => static::rlsRequireRole('admin', 'gestor'));
    }

    public function notasFiscais(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }
}
