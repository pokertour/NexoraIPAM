<?php

use App\Http\Middleware\BrowserLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Reverse proxies on a private network are trusted by default for X-Forwarded-*,
        // so that HTTPS links and the client IP survive the proxy. TRUSTED_PROXIES overrides it.
        $proxies = (string) env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1,fc00::/7');
        $middleware->trustProxies(at: trim($proxies) === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        $middleware->web(append: [BrowserLocale::class]);
        $middleware->api(append: [BrowserLocale::class]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['enable_password', 'disable_password', 'setup_code', 'disable_code', 'code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
