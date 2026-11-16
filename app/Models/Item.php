<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Item do catálogo de TI (o "dicionário" do que pode existir no estoque). */
class Item extends Model
{
    use SoftDeletes;

    protected $table = 'itens';

    protected $fillable = [
        'categoria_id', 'nome', 'descricao', 'unidade_medida',
        'quantidade_minima', 'lead_time_dias',
    ];

    protected $casts = [
        'quantidade_minima' => 'integer',
        'lead_time_dias' => 'integer',
    ];

    protected static function booted(): void
    {
        // Todo item do catálogo nasce com uma linha de saldo zerada.
        static::created(function (Item $item) {
            $item->estoque()->create(['saldo_fisico' => 0, 'saldo_reservado' => 0]);
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
