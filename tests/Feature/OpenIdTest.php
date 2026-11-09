<?php

namespace Tests\Feature;

use App\Http\Controllers\OpenIdController;
use App\Livewire\Preferences;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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
        $this->assertSame('profile email openid', $query['scope'], 'Without configured scopes, profile and email are asked.');
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

    public function test_administrators_choose_the_scopes_sent_to_the_provider(): void
    {
        $this->withoutVite();
        $issuer = 'https://sso.example.com';
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(Preferences::class)->assertSet('oidc.scopes', 'openid profile email')
            ->set('oidc.enabled', true)->set('oidc.issuer', $issuer)->set('oidc.client_id', 'nexora')->set('oidc.secret', 'secret')
            ->set('oidc.scopes', 'profile "email"')->call('save', 'oidc')->assertHasErrors('oidc.scopes')
            ->set('oidc.secret', 'secret')->set('oidc.scopes', ' email, profile groups  offline_access email ')->call('save', 'oidc')->assertHasNoErrors()
            ->assertSet('oidc.scopes', 'openid email profile groups offline_access');
        $this->assertSame('openid email profile groups offline_access', Setting::read('oidc')['scopes']);
        $this->assertSame([null, 'openid email profile groups offline_access'], AuditLog::where('action', 'settings.updated')->latest('id')->first()->changes['scopes']);

        auth()->logout();
        Http::fake([$issuer.'/.well-known/openid-configuration' => Http::response([
            'issuer' => $issuer, 'authorization_endpoint' => $issuer.'/authorize', 'token_endpoint' => $issuer.'/token',
            'jwks_uri' => $issuer.'/jwks', 'code_challenge_methods_supported' => ['S256'],
        ])]);
        parse_str(parse_url($this->get(route('oidc.start'))->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('email profile groups offline_access openid', $query['scope']);
    }

    public function test_the_login_page_offers_the_preferred_method_first_with_its_label_and_icon(): void
    {
        $this->withoutVite();
        Setting::write('ldap', ['enabled' => true, 'uri' => 'ldaps://dc.example.test', 'button_label' => 'Active Directory', 'button_icon' => 'building']);
        Setting::write('oidc', ['enabled' => true, 'issuer' => 'https://sso.example.com', 'button_label' => 'Se connecter avec Microsoft', 'button_icon' => 'microsoft']);

        $this->get(route('login'))->assertOk()
            ->assertSeeInOrder(['name="provider" value="local"', 'Compte interne', 'name="provider" value="ldap"', 'Active Directory', 'ou</span>', 'Se connecter avec Microsoft'], false);

        Setting::write('login', ['primary' => 'oidc']);
        $this->get(route('login'))->assertSeeInOrder(['Se connecter avec Microsoft', 'ou</span>', 'name="email"'], false)
            ->assertSee('#f25022', false);

        Setting::write('login', ['primary' => 'ldap']);
        $this->get(route('login'))->assertSeeInOrder(['value="ldap" checked', 'Active Directory', 'value="local"', 'Se connecter avec Microsoft'], false);

        Setting::write('oidc', ['enabled' => false] + Setting::read('oidc'));
        Setting::write('ldap', ['enabled' => false] + Setting::read('ldap'));
        $this->get(route('login'))->assertSee('name="provider" value="local"', false)->assertDontSee('Active Directory')->assertDontSee('Microsoft');
    }

    public function test_administrators_set_the_buttons_and_the_preferred_method(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(Preferences::class)->assertSet('login.primary', 'local')->assertSet('oidc.button_icon', 'key')
            ->set('login.primary', 'fax')->call('save', 'login')->assertHasErrors('login.primary')
            ->set('login.primary', 'oidc')->call('save', 'login')->assertHasNoErrors()
            ->set('oidc.enabled', false)->set('oidc.button_icon', 'skull')->call('save', 'oidc')->assertHasErrors('oidc.button_icon')
            ->set('oidc.button_icon', 'shield')->set('oidc.button_label', '  Portail SSO  ')->call('save', 'oidc')->assertHasNoErrors();

        $this->assertSame(['primary' => 'oidc'], Setting::read('login'));
        $this->assertSame(['Portail SSO', 'shield'], [Setting::read('oidc')['button_label'], Setting::read('oidc')['button_icon']]);
        $this->assertSame([null, 'oidc'], AuditLog::where('subject_label', 'login')->sole()->changes['primary']);
    }

    public function test_the_profile_comes_from_userinfo_when_the_id_token_lacks_it(): void
    {
        $issuer = 'https://zitadel.example.com';
        $identifier = OpenIdController::identifier($issuer, 'zitadel-user');
        $placeholder = User::factory()->create(['provider' => 'oidc', 'external_id' => $identifier, 'name' => 'Compte OIDC', 'email' => 'oidc-0ba354@accounts.invalid']);

        $this->signInWithZitadel($issuer, 'zitadel-user', ['sub' => 'zitadel-user', 'email' => 'Alice@Example.test', 'email_verified' => true, 'given_name' => 'Alice', 'family_name' => 'Martin']);

        $this->assertAuthenticatedAs($placeholder);
        $this->assertSame(['Alice Martin', 'alice@example.test'], [$placeholder->fresh()->name, $placeholder->fresh()->email]);
    }

    public function test_userinfo_about_another_subject_is_ignored(): void
    {
        $issuer = 'https://zitadel.example.com';
        $this->signInWithZitadel($issuer, 'zitadel-user', ['sub' => 'someone-else', 'email' => 'mallory@example.test', 'email_verified' => true, 'name' => 'Mallory']);
        $account = User::where('external_id', OpenIdController::identifier($issuer, 'zitadel-user'))->sole();
        $this->assertSame('Compte OIDC', $account->name);
        $this->assertStringEndsWith('@accounts.invalid', $account->email);
    }

    public function test_an_unverified_email_from_userinfo_is_not_used(): void
    {
        $this->signInWithZitadel('https://zitadel.example.com', 'zitadel-user', ['sub' => 'zitadel-user', 'email' => 'bob@example.test', 'email_verified' => false, 'name' => 'Bob']);
        $account = User::where('provider', 'oidc')->sole();
        $this->assertSame('Bob', $account->name);
        $this->assertStringEndsWith('@accounts.invalid', $account->email);
    }

    /**
     * Signs in like Zitadel by default: the ID token carries no profile, the
     * userinfo endpoint does.
     *
     * @param  array<string, mixed>  $userinfo
     */
    private function signInWithZitadel(string $issuer, string $subject, array $userinfo): void
    {
        Setting::write('oidc', ['enabled' => true, 'issuer' => $issuer, 'client_id' => 'nexora', 'secret' => 'secret']);
        $discovery = [
            'issuer' => $issuer, 'authorization_endpoint' => $issuer.'/authorize', 'token_endpoint' => $issuer.'/token',
            'jwks_uri' => $issuer.'/jwks', 'userinfo_endpoint' => $issuer.'/userinfo', 'code_challenge_methods_supported' => ['S256'],
        ];
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $nonce = null;
        Http::fake(function (Request $request) use ($issuer, $discovery, $key, $details, $subject, $userinfo, &$nonce) {
            return match (strtok($request->url(), '?')) {
                $issuer.'/.well-known/openid-configuration' => Http::response($discovery),
                $issuer.'/jwks' => Http::response(['keys' => [['kid' => 'k', 'kty' => 'RSA', 'use' => 'sig', 'n' => $this->encode($details['rsa']['n']), 'e' => $this->encode($details['rsa']['e'])]]]),
                $issuer.'/token' => Http::response(['access_token' => 'access-token', 'id_token' => (function () use ($issuer, $key, $subject, &$nonce): string {
                    $jwt = $this->encode(json_encode(['alg' => 'RS256', 'kid' => 'k'])).'.'.$this->encode(json_encode(['iss' => $issuer, 'aud' => 'nexora', 'sub' => $subject, 'nonce' => $nonce, 'iat' => time(), 'exp' => time() + 120]));
                    openssl_sign($jwt, $signature, $key, OPENSSL_ALGO_SHA256);

                    return $jwt.'.'.$this->encode($signature);
                })()]),
                $issuer.'/userinfo' => $request->hasHeader('Authorization', 'Bearer access-token') ? Http::response($userinfo) : Http::response([], 401),
                default => Http::response([], 404),
            };
        });

        parse_str(parse_url($this->get(route('oidc.start'))->headers->get('Location'), PHP_URL_QUERY), $query);
        $nonce = $query['nonce'];
        $this->get(route('oidc.callback', ['code' => 'valid-code', 'state' => $query['state']]))->assertRedirectToRoute('dashboard');
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
