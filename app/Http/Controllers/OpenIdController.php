<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\ExternalAccounts;
use App\Support\OpenIdClient;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OpenIdController extends Controller
{
    public function __invoke(Request $request, ExternalAccounts $accounts): RedirectResponse
    {
        $settings = Setting::read('oidc');
        abort_unless($settings['enabled'] ?? false, 404);

        $callback = $request->routeIs('oidc.callback');
        $fingerprint = hash('sha256', serialize($settings));
        $transaction = $request->session()->get('oidc.transaction', []);
        $state = $request->session()->get('oidc.openid_connect_state');
        if ($callback && (
            ! is_string($request->query('code')) || ! is_string($request->query('state')) ||
            ! is_string($request->session()->get('oidc.openid_connect_code_verifier')) ||
            ! is_string($state) || ! hash_equals($state, $request->query('state')) ||
            ($transaction['fingerprint'] ?? null) !== $fingerprint ||
            ($transaction['expires'] ?? 0) < time()
        )) {
            $request->session()->forget('oidc');

            return $this->failed();
        }

        if (! $callback) {
            $request->session()->forget('oidc');
            $request->session()->put('oidc.transaction', [
                'fingerprint' => $fingerprint,
                'expires' => time() + 300,
            ]);
        }

        try {
            $client = new OpenIdClient($settings['issuer'], $settings['client_id'], $settings['secret'], $settings['issuer']);
            $client->setIssuerValidator(fn ($issuer): bool => $issuer === $settings['issuer']);
            $client->setRedirectURL(route('oidc.callback'));
            $client->setCodeChallengeMethod('S256');
            $client->addScope(['profile', 'email']);

            if ($client->authenticateRequest($callback ? $request->only('code', 'state', 'error') : []) && $callback) {
                $subject = $client->getVerifiedClaims('sub');
                $email = $client->getVerifiedClaims('email');
                $name = $client->getVerifiedClaims('name');
                $user = $accounts->resolve(
                    'oidc',
                    self::identifier($settings['issuer'], $subject),
                    is_string($email) && $client->getVerifiedClaims('email_verified') === true ? $email : null,
                    is_string($name) ? $name : null,
                );

                if ($user) {
                    Auth::login($user);
                    $request->session()->regenerate();

                    return redirect()->intended(route('dashboard'));
                }
            }
        } catch (HttpResponseException $redirect) {
            throw $redirect;
        } catch (\Throwable $exception) {
            Log::warning('Échec OpenID Connect', ['type' => $exception::class]);
        } finally {
            if ($callback) {
                $request->session()->forget('oidc');
            }
        }

        return $this->failed();
    }

    public static function identifier(string $issuer, string $subject): string
    {
        return hash('sha256', $issuer."\0".$subject);
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['login' => __('Connexion OpenID Connect refusée ou compte non autorisé.')]);
    }
}
