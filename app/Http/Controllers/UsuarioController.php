<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class UsuarioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::orderBy('name');

        if ($busca = $request->query('search')) {
            $like = $this->termoLike((string) $busca);
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        return UserResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request): UserResource
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', new Enum(Role::class)],
        ]);

        $usuario = new User();
        $usuario->fill([
            'name' => $dados['name'],
            'email' => $dados['email'],
            'password' => Hash::make($dados['password']),
        ]);
        $usuario->role = $dados['role'];
        $usuario->save();

        return new UserResource($usuario->refresh());
    }

    public function update(Request $request, User $usuario): UserResource
    {
        $dados = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($usuario->id)],
            'password' => ['nullable', Password::min(8)],
            'role' => ['sometimes', new Enum(Role::class)],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        if (empty($dados['password'])) {
            unset($dados['password']);
        } else {
            $dados['password'] = Hash::make($dados['password']);
        }

        $role = $dados['role'] ?? $usuario->role->value;

        if ($usuario->is($request->user()) && (($dados['ativo'] ?? true) === false || $role !== Role::Admin->value)) {
            abort(422, 'Não pode desativar nem rebaixar o seu próprio utilizador.');
        }

        $usuario->fill(array_intersect_key($dados, array_flip(['name', 'email', 'password'])));
        if (array_key_exists('role', $dados)) {
            $usuario->role = $dados['role'];
        }
        if (array_key_exists('ativo', $dados)) {
            $usuario->ativo = $dados['ativo'];
        }
        $usuario->save();

        if (($dados['ativo'] ?? true) === false || isset($dados['password'])) {
            app(JwtService::class)->revokeAllForUser($usuario);
        }

        return new UserResource($usuario);
    }
}
