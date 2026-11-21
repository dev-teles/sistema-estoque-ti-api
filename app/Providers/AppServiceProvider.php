<?php

namespace App\Providers;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // Guard JWT stateless (HS256).
        Auth::viaRequest('jwt', fn (Request $request) => app(JwtService::class)->authenticate($request));

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
