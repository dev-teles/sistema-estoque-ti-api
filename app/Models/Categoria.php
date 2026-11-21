<?php

namespace App\Models;

use App\Models\Concerns\HasRowLevelSecurity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use HasRowLevelSecurity;

    protected $fillable = ['nome', 'descricao'];

    protected static function booted(): void
    {
        static::creating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::updating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::deleting(fn () => static::rlsRequireRole('admin', 'gestor'));
    }

    public function itens(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
