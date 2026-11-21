<?php

use App\Http\Middleware\BotProtection;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SanitizeInput;
use App\Models\LogAcesso;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            BotProtection::class,
            SanitizeInput::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'bot' => BotProtection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A API responde sempre em JSON, mesmo sem o cabeçalho Accept.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                LogAcesso::registrar('acesso_negado', $request->user());

                return response()->json([
                    'message' => 'Muitas requisições. Aguarde alguns instantes antes de tentar novamente.',
                ], 429, $e->getHeaders());
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Registo não encontrado.'], 404);
            }
        });

        $exceptions->render(function (MassAssignmentException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                LogAcesso::registrar('acesso_negado', $request->user());

                return response()->json([
                    'message' => 'Operação bloqueada: tentativa de atribuição em massa de campo protegido.',
                ], 422);
            }
        });
    })->create();
