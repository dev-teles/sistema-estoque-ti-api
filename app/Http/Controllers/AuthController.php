<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\LogAcesso;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly JwtService $jwt) {}

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
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

        $updates = ['ultimo_login_em' => now()];
        if (Hash::needsRehash($user->password)) {
            $updates['password'] = Hash::make($dados['password']);
        }
        $user->forceFill($updates)->save();
        LogAcesso::registrar('login', $user);

        $jwt = $this->jwt->issue($user);

        return response()->json([
            'token' => $jwt['token'],
            'token_type' => 'Bearer',
            'expires_in' => $jwt['expires_in'],
            'user' => new UserResource($user),
        ])->withCookie($this->jwt->makeCookie($jwt['token']));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->jwt->revokeToken($this->jwt->extractToken($request));
        LogAcesso::registrar('logout', $request->user());

        return response()->json(['message' => 'Sessão terminada.'])
            ->withCookie($this->jwt->forgetCookie());
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
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $this->jwt->revokeAllForUser($user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Token inválido ou expirado.']);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }
}
