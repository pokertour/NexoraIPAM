<?php

namespace App\Http\Middleware;

use App\Console\Commands\InstallAdmin;
use App\Models\Setting;
use App\Support\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $settings = Setting::read($user->provider);
        $enabled = $settings['enabled'] ?? $user->provider === 'local';
        if ($user->provider === 'ldap') {
            $enabled = $enabled && filled($settings['group_dn'] ?? null);
        }

        $factorRequired = $user->provider === 'local' && $user->two_factor;
        $factorVerified = ! $factorRequired || hash_equals(TwoFactor::fingerprint($user), (string) $request->session()->get('two_factor_verified'));

        if (! $user->active || ! $factorVerified || (! $enabled && $user->email !== InstallAdmin::EMAIL)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        if ($user->must_change_password && ! $request->routeIs('password.*', 'logout')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
