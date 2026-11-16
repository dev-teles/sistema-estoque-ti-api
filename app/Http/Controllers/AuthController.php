<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\LogAcesso;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $dados['email'])->first();

        if (! $user || ! Hash::check($dados['password'], $user->password)) {
            LogAcesso::registrar('login_falhou', $user, $dados['email']);
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        if (! $user->ativo) {
            LogAcesso::registrar('login_falhou', $user);
            throw ValidationException::withMessages(['email' => 'Utilizador inativo. Contacte o administrador.']);
        }

        $user->forceFill(['ultimo_login_em' => now()])->save();
        LogAcesso::registrar('login', $user);

        $token = $user->createToken($dados['device_name'] ?? 'spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        LogAcesso::registrar('logout', $request->user());

        return response()->json(['message' => 'Sessão terminada.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Resposta neutra: não revela se o e-mail existe.
        return response()->json(['message' => 'Se o e-mail estiver registado, receberá um link para redefinir a senha.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete(); // invalida sessões anteriores
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Token inválido ou expirado.']);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }
}
