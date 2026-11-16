<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FornecedorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Fornecedor::orderBy('nome');

        if ($busca = $request->query('search')) {
            $digitos = preg_replace('/\D/', '', $busca);
            $query->where(function ($q) use ($busca, $digitos) {
                $q->where('nome', 'like', "%{$busca}%");
                if ($digitos !== '') {
                    $q->orWhere('cnpj', 'like', "%{$digitos}%");
                }
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['cnpj' => preg_replace('/\D/', '', (string) $request->input('cnpj'))]);

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:150'],
            'cnpj' => ['required', 'digits:14', 'unique:fornecedores,cnpj'],
            'contato_nome' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['data' => Fornecedor::create($dados)], 201);
    }

    public function update(Request $request, Fornecedor $fornecedor): JsonResponse
    {
        if ($request->has('cnpj')) {
            $request->merge(['cnpj' => preg_replace('/\D/', '', (string) $request->input('cnpj'))]);
        }

        $dados = $request->validate([
            'nome' => ['sometimes', 'string', 'max:150'],
            'cnpj' => ['sometimes', 'digits:14', Rule::unique('fornecedores', 'cnpj')->ignore($fornecedor->id)],
            'contato_nome' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
        ]);

        $fornecedor->update($dados);

        return response()->json(['data' => $fornecedor]);
    }
}
