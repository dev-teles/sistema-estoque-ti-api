<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fornecedores de equipamentos, peças e insumos de TI. */
class Fornecedor extends Model
{
    protected $table = 'fornecedores';

    protected $fillable = ['nome', 'cnpj', 'contato_nome', 'email', 'telefone'];

    public function notasFiscais(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }
}
