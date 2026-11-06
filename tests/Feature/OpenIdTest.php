<?php

namespace Tests\Feature;

use App\Http\Controllers\OpenIdController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenIdTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('accounts')]
    public function test_a_signed_code_exchange_resolves_the_account_by_subject(bool $existing): void
    {
        $issuer = 'https://sso.example.com';
        $subject = 'external-user-22';
        $identifier = OpenIdController::identifier($issuer, $subject);
        $internal = User::factory()->create(['email' => 'shared@example.test', 'role' => 'admin']);
        $user = $existing ? User::factory()->create([
            'provider' => 'oidc', 'external_id' => $identifier, 'role' => 'operator',
        ]) : null;
        Setting::write('oidc', ['enabled' => true, 'issuer' => $issuer, 'client_id' => 'nexora', 'secret' => 'secret']);
        Http::preventStrayRequests();
        $discovery = [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/authorize',
            'token_endpoint' => $issuer.'/token',
            'jwks_uri' => $issuer.'/jwks',
            'code_challenge_methods_supported' => ['S256'],
        ];
        Http::fake([$issuer.'/.well-known/openid-configuration' => Http::response($discovery)]);
        $redirect = $this->get(route('oidc.start'))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $jwt = $this->encode(json_encode(['alg' => 'RS256', 'kid' => 'idp-key'], JSON_THROW_ON_ERROR)).'.'
            .$this->encode(json_encode([
                'iss' => $issuer, 'aud' => 'nexora', 'sub' => $subject, 'nonce' => $query['nonce'],
                'iat' => time(), 'exp' => time() + 120,
                'email' => $internal->email, 'email_verified' => true, 'name' => 'Compte SSO',
            ], JSON_THROW_ON_ERROR));
        openssl_sign($jwt, $signature, $key, OPENSSL_ALGO_SHA256);

        Http::fake([
            $issuer.'/token' => Http::response(['access_token' => 'access-token', 'id_token' => $jwt.'.'.$this->encode($signature)]),
            $issuer.'/jwks' => Http::response(['keys' => [[
                'kid' => 'idp-key', 'kty' => 'RSA', 'use' => 'sig',
                'n' => $this->encode($details['rsa']['n']), 'e' => $this->encode($details['rsa']['e']),
            ]]]),
        ]);
        $this->get(route('oidc.callback', ['code' => 'valid-code', 'state' => $query['state']]))
            ->assertRedirectToRoute('dashboard');
        $account = User::where('external_id', $identifier)->sole();
        $this->assertAuthenticatedAs($account);
        $this->assertSame($existing ? $user->id : 'viewer', $existing ? $account->id : $account->role);
        $this->assertNotSame($internal->id, $account->id);
        $this->assertSame('admin', $internal->fresh()->role);
    }

    public function test_the_callback_rejects_missing_state_before_exchanging_the_code(): void
    {
        Setting::write('oidc', ['enabled' => true, 'issuer' => 'https://sso.example.com', 'client_id' => 'nexora', 'secret' => 'secret']);
        Http::fake();

        $this->get(route('oidc.callback', ['code' => 'unknown-code', 'state' => 'unbound-state']))
            ->assertRedirectToRoute('login')->assertSessionHasErrors('login');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public static function accounts(): array
    {
        return ['existing subject' => [true], 'first access' => [false]];
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
