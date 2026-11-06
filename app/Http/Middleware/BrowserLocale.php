<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BrowserLocale
{
    public const COOKIE = 'nexora-locale';

    public const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);
        app()->setLocale(in_array($locale, self::SUPPORTED, true)
            ? $locale
            : $request->getPreferredLanguage(self::SUPPORTED));

        return $next($request);
    }
}
