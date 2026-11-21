<?php

namespace App\Providers;

use App\Models\Concerns\HasRowLevelSecurity;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Em desenvolvimento: falha cedo em lazy loading (deteta queries N+1).
        Model::preventLazyLoading(! $this->app->isProduction());

        // Guard JWT stateless (HS256) + vínculo de contexto RLS na sessão da base de dados.
        Auth::viaRequest('jwt', function (Request $request) {
            $user = app(JwtService::class)->authenticate($request);
            HasRowLevelSecurity::bindDbSession($user);

            return $user;
        });

        Event::listen(Authenticated::class, fn (Authenticated $e) => HasRowLevelSecurity::bindDbSession($e->user instanceof User ? $e->user : null));
        Event::listen(Logout::class, fn () => HasRowLevelSecurity::bindDbSession(null));

        // Proteção contra força bruta no login / recuperação de senha.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.$request->input('email')));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        // O link de redefinição aponta para a SPA (React), não para uma rota Laravel.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $base = rtrim(explode(',', (string) config('app.frontend_url'))[0], '/');

            return $base.'/redefinir-senha?token='.$token.'&email='.urlencode($user->email);
        });
    }
}
