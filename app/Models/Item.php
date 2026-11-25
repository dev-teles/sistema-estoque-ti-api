<?php

namespace App\Models;

use App\Models\Concerns\HasRowLevelSecurity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Item do catálogo de TI (o "dicionário" do que pode existir no estoque). */
class Item extends Model
{
    use HasRowLevelSecurity, SoftDeletes;

    protected $table = 'itens';

    protected $fillable = [
        'categoria_id', 'nome', 'numero_serie', 'descricao', 'unidade_medida',
        'quantidade_minima', 'lead_time_dias',
    ];

    protected $casts = [
        'quantidade_minima' => 'integer',
        'lead_time_dias' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::updating(fn () => static::rlsRequireRole('admin', 'gestor'));
        static::deleting(fn () => static::rlsRequireRole('admin', 'gestor'));

        // Todo item do catálogo nasce com uma linha de saldo zerada.
        static::created(function (Item $item) {
            $item->estoque()->save((new Estoque())->forceFill(['saldo_fisico' => 0, 'saldo_reservado' => 0]));
        });
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function estoque(): HasOne
    {
        return $this->hasOne(Estoque::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(Movimentacao::class);
    }
}
