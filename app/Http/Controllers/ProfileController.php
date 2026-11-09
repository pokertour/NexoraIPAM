<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $pending = $request->session()->get('two_factor_setup');
        $codes = $request->session()->pull('recovery_codes');
        $user = $request->user();

        return response()->view('profile', [
            'user' => $user,
            'smtpEnabled' => (bool) (Setting::read('smtp')['enabled'] ?? false),
            'logins' => AuditLog::query()
                ->where(fn ($query) => $query->where('action', 'auth.login')->where('user_id', $user->id))
                ->orWhere(fn ($query) => $query->where('action', 'auth.failed')->where('subject_type', 'user')->where('subject_id', $user->id))
                ->latest('id')->limit(6)->get(),
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

    /**
     * Sends a confirmation link to the new address. The address only changes
     * once the link is opened, by the same user, within an hour.
     */
    public function requestEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->provider === 'local', 403);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $input = $request->validateWithBag('email', [
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id), Rule::notIn([$user->email])],
            'email_password' => ['required', 'current_password'],
        ], ['email.not_in' => __('C’est déjà votre adresse e-mail.')]);

        $smtp = Setting::read('smtp');
        if (! ($smtp['enabled'] ?? false)) {
            return to_route('profile')->withErrors(['email' => __('L’envoi d’e-mails n’est pas configuré. Demandez à un administrateur de modifier votre adresse.')], 'email')->withInput();
        }

        $url = URL::temporarySignedRoute('profile.email.verify', now()->addMinutes(60), ['user' => $user->id, 'hash' => sha1($input['email'])]);
        try {
            Mail::mailer('smtp')->raw(
                __("Bonjour :name,\n\nPour utiliser cette adresse avec votre compte NexoraIPAM, ouvrez ce lien dans l’heure, connecté à votre compte :\n\n:url\n\nSi vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre adresse actuelle reste inchangée.", ['name' => $user->name, 'url' => $url]),
                function ($message) use ($input, $smtp): void {
                    $message->from($smtp['from'], config('app.name'))->to($input['email'])->subject(__('Confirmez votre nouvelle adresse e-mail'));
                },
            );
        } catch (Throwable $exception) {
            Log::warning('Échec de l’envoi de la confirmation d’e-mail', ['type' => $exception::class, 'user' => $user->id]);

            return to_route('profile')->withErrors(['email' => __('L’e-mail de confirmation n’a pas pu être envoyé. Réessayez plus tard ou contactez un administrateur.')], 'email')->withInput();
        }

        $previous = $user->pending_email;
        $user->forceFill(['pending_email' => $input['email']])->save();
        Audit::record('email.change_requested', $user, ['pending_email' => [$previous, $input['email']]]);

        return to_route('profile')->with('notice', __('Un lien de confirmation a été envoyé à :email.', ['email' => $input['email']]));
    }

    public function verifyEmail(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->is($user) && $user->provider === 'local', 403);
        $pending = $user->pending_email;
        if (! $request->hasValidSignature() || $pending === null || ! hash_equals(sha1($pending), (string) $request->query('hash'))) {
            return to_route('profile')->withErrors(['email' => __('Ce lien de confirmation a expiré ou a déjà été utilisé. Demandez-en un nouveau.')], 'email');
        }
        if (User::where('email', $pending)->whereKeyNot($user->id)->exists()) {
            $user->forceFill(['pending_email' => null])->save();

            return to_route('profile')->withErrors(['email' => __('Cette adresse est désormais utilisée par un autre compte.')], 'email');
        }

        $previous = $user->email;
        $user->forceFill(['email' => $pending, 'pending_email' => null, 'email_verified_at' => now()])->save();
        $this->notifyPreviousAddress($user, $previous);

        return to_route('profile')->with('notice', __('Adresse e-mail mise à jour.'));
    }

    public function cancelEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->provider === 'local', 403);
        if ($user->pending_email !== null) {
            Audit::record('email.change_cancelled', $user, ['pending_email' => [$user->pending_email, null]]);
            $user->forceFill(['pending_email' => null])->save();
        }

        return to_route('profile')->with('notice', __('Changement d’adresse annulé.'));
    }

    /** Warns the former address, so an unexpected change does not go unnoticed. */
    private function notifyPreviousAddress(User $user, string $previous): void
    {
        $smtp = Setting::read('smtp');
        if (! ($smtp['enabled'] ?? false) || str_ends_with($previous, '@accounts.invalid')) {
            return;
        }

        try {
            Mail::mailer('smtp')->raw(
                __("Bonjour :name,\n\nL’adresse e-mail de votre compte NexoraIPAM a été remplacée par :email.\n\nSi vous n’êtes pas à l’origine de ce changement, contactez un administrateur.", ['name' => $user->name, 'email' => $user->email]),
                function ($message) use ($previous, $smtp): void {
                    $message->from($smtp['from'], config('app.name'))->to($previous)->subject(__('Adresse e-mail de votre compte modifiée'));
                },
            );
        } catch (Throwable $exception) {
            Log::warning('Échec de l’avis de changement d’e-mail', ['type' => $exception::class, 'user' => $user->id]);
        }
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
