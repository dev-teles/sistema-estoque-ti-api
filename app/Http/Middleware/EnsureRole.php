<?php

namespace App\Http\Middleware;

use App\Models\LogAcesso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC: `->middleware('role:admin,gestor')`.
 * Tentativas negadas ficam registadas em `logs_acesso`.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole(...$roles)) {
            LogAcesso::registrar('acesso_negado', $user);

            return response()->json(['message' => 'Acesso negado: o seu perfil não tem permissão para esta operação.'], 403);
        }

        return $next($request);
    }
}
