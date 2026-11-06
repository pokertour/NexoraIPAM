<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $pending = $request->session()->get('two_factor_setup');
        $codes = $request->session()->pull('recovery_codes');
        $user = $request->user();

        return response()->view('profile', [
            'user' => $user,
            'setupKey' => $user->provider === 'local' && ! $user->two_factor && ($pending['expires'] ?? 0) > now()->timestamp
                ? Crypt::decryptString($pending['key']) : null,
            'recoveryCodes' => $user->provider === 'local' && $codes ? Crypt::decrypt($codes) : [],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function rename(Request $request): RedirectResponse
    {
        abort_unless($request->user()->provider === 'local', 403);
        $request->user()->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return to_route('profile')->with('notice', __('Nom mis à jour.'));
    }

    public function setup(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        abort_unless($request->user()->provider === 'local', 403);
        abort_if($request->user()->two_factor, 409);

        $request->session()->put('two_factor_setup', [
            'key' => Crypt::encryptString($twoFactor->secret()),
            'expires' => now()->addMinutes(10)->timestamp,
        ]);

        return to_route('profile');
    }

    public function enable(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        abort_unless($request->user()->provider === 'local', 403);
        $input = $request->validate([
            'enable_password' => ['required', 'current_password'],
            'setup_code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ]);

        $pending = $request->session()->get('two_factor_setup');
        if (($pending['expires'] ?? 0) <= now()->timestamp) {
            return to_route('profile')->withErrors(['setup_code' => __('La configuration a expiré. Générez une nouvelle clé.')]);
        }

        $secret = Crypt::decryptString($pending['key']);
        $step = $twoFactor->matchingStep($secret, $input['setup_code']);
        if ($step === null) {
            throw ValidationException::withMessages(['setup_code' => __('Ce code est invalide.')]);
        }

        $codes = $twoFactor->recoveryCodes();
        $user = DB::transaction(function () use ($request, $secret, $step, $codes): User {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->provider === 'local' && ! $user->two_factor, 409);
            abort_unless(hash_equals($request->user()->password, $user->password), 409);
            $user->forceFill(['two_factor' => [
                'secret' => $secret,
                'step' => $step,
                'recovery' => array_map(fn (string $code): string => hash('sha256', $code), $codes),
            ]])->save();

            return $user;
        }, attempts: 3);

        Audit::record('two_factor.enabled', $user);
        $request->session()->forget('two_factor_setup');
        $request->session()->put('two_factor_verified', TwoFactor::fingerprint($user));
        $request->session()->regenerate(true);

        return to_route('profile')->with('recovery_codes', Crypt::encrypt($codes))->with('notice', __('Authentification à deux facteurs activée.'));
    }

    public function disable(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        abort_unless($request->user()->provider === 'local', 403);
        $input = $request->validate([
            'disable_password' => ['required', 'current_password'],
            'disable_code' => ['required', 'string', 'max:20'],
        ]);

        DB::transaction(function () use ($request, $input, $twoFactor): void {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->provider === 'local' && $user->two_factor, 409);
            abort_unless(hash_equals($request->user()->password, $user->password)
                && hash_equals(TwoFactor::fingerprint($user), (string) $request->session()->get('two_factor_verified')), 409);
            if (! $twoFactor->consume($user, $input['disable_code'])) {
                throw ValidationException::withMessages(['disable_code' => __('Ce code est invalide ou a déjà été utilisé.')]);
            }
            $user->forceFill(['two_factor' => null])->save();
        }, attempts: 3);

        $request->session()->forget('two_factor_verified');
        Audit::record('two_factor.disabled', $request->user());

        return to_route('profile')->with('notice', __('Authentification à deux facteurs désactivée.'));
    }
}
