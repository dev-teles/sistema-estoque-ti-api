<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Categoria::withCount('itens')->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:100', 'unique:categorias,nome'],
            'descricao' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => Categoria::create($dados)], 201);
    }

    public function update(Request $request, Categoria $categoria): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['sometimes', 'string', 'max:100', Rule::unique('categorias', 'nome')->ignore($categoria->id)],
            'descricao' => ['nullable', 'string', 'max:255'],
        ]);

        $categoria->update($dados);

        return response()->json(['data' => $categoria]);
    }
}
