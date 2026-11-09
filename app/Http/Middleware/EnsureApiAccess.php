<?php

namespace App\Http\Middleware;

use App\Console\Commands\InstallAdmin;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the workspace access rules to API tokens and checks that the token
 * allows the request: "read" for safe methods, "write" for the others.
 */
class EnsureApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $settings = Setting::read($user->provider);
        $enabled = $settings['enabled'] ?? $user->provider === 'local';
        if ($user->provider === 'ldap') {
            $enabled = $enabled && filled($settings['group_dn'] ?? null);
        }

        if (! $user->active || $user->must_change_password || (! $enabled && ! InstallAdmin::isInstallAccount($user))) {
            abort(403, __('Ce compte ne peut pas utiliser l’API.'));
        }

        $ability = $request->isMethodSafe() ? 'read' : 'write';
        if (! $user->tokenCan($ability)) {
            abort(403, __('Ce jeton ne permet pas cette action.'));
        }

        return $next($request);
    }
}
