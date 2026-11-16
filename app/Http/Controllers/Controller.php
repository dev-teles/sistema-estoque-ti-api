<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class Controller
{
    /** Regra `exists` com opção de restringir a itens ativos (sem soft-delete). */
    protected function existe(string $tabela, bool $apenasAtivos = false): Exists
    {
        $rule = Rule::exists($tabela, 'id');

        return $apenasAtivos ? $rule->whereNull('deleted_at') : $rule;
    }

    protected function perPage(Request $request, int $padrao = 15): int
    {
        return max(1, min(100, (int) $request->query('per_page', $padrao)));
    }
}
