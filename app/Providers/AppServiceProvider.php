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
use Illuminate\Support\Facades\URL;
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
        Model::preventSilentlyDiscardingAttributes(true);

        if ($this->app->isProduction() || filter_var(env('FORCE_HTTPS', false), FILTER_VALIDATE_BOOLEAN)) {
            URL::forceScheme('https');
        }

        // Guard JWT stateless (HS256) + vínculo de contexto RLS na sessão da base de dados.
        Auth::viaRequest('jwt', function (Request $request) {
            $user = app(JwtService::class)->authenticate($request);
            HasRowLevelSecurity::bindDbSession($user);

            return $user;
        });

        Event::listen(Authenticated::class, fn (Authenticated $e) => HasRowLevelSecurity::bindDbSession($e->user instanceof User ? $e->user : null));
        Event::listen(Logout::class, fn () => HasRowLevelSecurity::bindDbSession(null));

        // Proteção contra força bruta e abuso de API (Rate Limiting em camadas).
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip().'|'.strtolower((string) $request->input('email'))),
            Limit::perMinute(15)->by('auth_ip:'.$request->ip()),
        ]);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(20)->by('webhook_ip:'.$request->ip()));

        // O link de redefinição aponta para a SPA (React), não para uma rota Laravel.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $base = rtrim(explode(',', (string) config('app.frontend_url'))[0], '/');

            return $base.'/redefinir-senha?token='.$token.'&email='.urlencode($user->email);
        });
    }
}
