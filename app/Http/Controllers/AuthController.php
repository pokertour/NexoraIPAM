<?php

namespace App\Http\Controllers;

use App\Console\Commands\InstallAdmin;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use App\Support\ExternalAccounts;
use App\Support\LdapLogin;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function show(): View
    {
        return view('auth.login', [
            'ldap' => Setting::read('ldap')['enabled'] ?? false,
            'oidc' => Setting::read('oidc')['enabled'] ?? false,
        ]);
    }

    public function login(Request $request, LdapLogin $ldap, ExternalAccounts $accounts): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'provider' => ['required', 'in:local,ldap'],
        ]);
        $email = strtolower($input['email']);
        $key = 'nexora-login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['login' => __('Trop de tentatives. Réessayez dans une minute.')])->withInput($request->only('email', 'provider'));
        }

        RateLimiter::hit($key, 60);
        if ($input['provider'] === 'ldap') {
            $settings = Setting::read('ldap');
            $identity = ($settings['enabled'] ?? false) && filled($settings['group_dn'] ?? null)
                ? $ldap->verify($settings, $email, $input['password']) : null;
            $user = $identity ? $accounts->resolve('ldap', $identity['id'], $email, $identity['name']) : null;
            $valid = $user !== null;
        } else {
            $user = User::where('email', $email)->where('provider', 'local')->where('active', true)->first();
            $local = Setting::read('local')['enabled'] ?? true;
            $valid = ($local || $email === InstallAdmin::EMAIL) && $user
                && Auth::getProvider()->validateCredentials($user, ['password' => $input['password']]);
        }

        if (! $valid) {
            Audit::record('auth.failed', $user, ['email' => [null, $email], 'provider' => [null, $input['provider']]], 'user', $email);

            return back()->withErrors(['login' => __('Identifiants invalides ou compte non autorisé.')])->withInput($request->only('email', 'provider'));
        }

        RateLimiter::clear($key);
        $request->session()->forget(['two_factor_login', 'two_factor_verified']);
        if ($user->provider === 'local') {
            Auth::getProvider()->rehashPasswordIfRequired($user, ['password' => $input['password']]);

            if ($user->two_factor) {
                $request->session()->regenerate(true);
                $request->session()->put('two_factor_login', [
                    'id' => $user->id,
                    'password' => hash('sha256', $user->password),
                    'factor' => TwoFactor::fingerprint($user),
                    'expires' => now()->addMinutes(5)->timestamp,
                ]);

                return to_route('two-factor.challenge');
            }
        }

        return $this->finishLogin($request, $user);
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if ($this->pendingLogin($request) === null) {
            return to_route('login');
        }

        return response()->view('auth.two-factor')->header('Cache-Control', 'private, no-store');
    }

    public function verifyChallenge(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $pending = $this->pendingLogin($request);
        if ($pending === null) {
            return to_route('login');
        }

        $key = 'nexora-two-factor:'.$pending['id'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => __('Trop de tentatives. Réessayez dans une minute.')]);
        }

        $input = $request->validate(['code' => ['required', 'string', 'max:20']]);
        RateLimiter::hit($key, 60);
        $user = DB::transaction(function () use ($pending, $input, $twoFactor): ?User {
            $user = User::where('provider', 'local')->where('active', true)->lockForUpdate()->find($pending['id']);
            $localEnabled = Setting::read('local')['enabled'] ?? true;
            if (! $user?->two_factor || (! $localEnabled && $user->email !== InstallAdmin::EMAIL)
                || ! hash_equals($pending['password'], hash('sha256', $user->password))
                || ! hash_equals($pending['factor'], TwoFactor::fingerprint($user))) {
                return null;
            }

            return $twoFactor->consume($user, $input['code']) ? $user : null;
        }, attempts: 3);

        if ($user === null) {
            return back()->withErrors(['code' => __('Ce code est invalide ou a déjà été utilisé.')]);
        }

        RateLimiter::clear($key);
        $request->session()->forget('two_factor_login');
        $request->session()->put('two_factor_verified', TwoFactor::fingerprint($user));

        return $this->finishLogin($request, $user);
    }

    private function pendingLogin(Request $request): ?array
    {
        $pending = $request->session()->get('two_factor_login');
        if (($pending['expires'] ?? 0) <= now()->timestamp) {
            $request->session()->forget('two_factor_login');

            return null;
        }

        return $pending;
    }

    private function finishLogin(Request $request, User $user): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate(true);
        Audit::record('auth.login', $user);

        return $user->must_change_password
            ? to_route('password.change')
            : redirect()->intended(route('dashboard'));
    }

    public function showPassword(Request $request): View
    {
        abort_unless($request->user()->provider === 'local', 403);

        return view('auth.password');
    }

    public function password(Request $request): RedirectResponse
    {
        abort_unless($request->user()->provider === 'local', 403);

        $input = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12), 'max:72'],
        ]);

        $firstChange = $request->user()->must_change_password;
        $request->user()->update(['password' => Hash::make($input['password']), 'must_change_password' => false]);
        $request->session()->regenerate();
        Audit::record('password.changed', $request->user());

        return to_route($firstChange ? 'dashboard' : 'profile')->with('notice', __('Mot de passe mis à jour.'));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($request->user()) {
            Audit::record('auth.logout', $request->user());
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
