<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('login-compartido', function (Request $request) {
            $usuario = $request->input('usuario');

            return Limit::perMinute(5)->by($request->ip().'|'.(is_string($usuario) ? mb_strtolower($usuario) : ''));
        });
        RateLimiter::for('recuperar-contrasena', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip().'|'.mb_strtolower((string) $request->input('identificador')));
        });
        RateLimiter::for('restablecer-contrasena', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.mb_strtolower((string) $request->input('identificador')));
        });
    }
}
