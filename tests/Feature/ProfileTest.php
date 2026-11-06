<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\LdapLogin;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_profile_enables_two_factor_and_requires_a_fresh_code_on_login(): void
    {
        $this->withoutVite();
        $this->freezeSecond();
        $password = 'Initial-password-42';
        $user = User::factory()->create(['password' => $password]);
        $this->actingAs($user);
        $this->post(route('profile.rename'), ['name' => 'Nom choisi', 'role' => 'admin'])->assertRedirectToRoute('profile');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nom choisi', 'role' => 'viewer']);

        $this->post(route('profile.two-factor.setup'))->assertRedirectToRoute('profile');
        $secret = Crypt::decryptString(session('two_factor_setup.key'));
        $twoFactor = app(TwoFactor::class);
        $code = $twoFactor->code($secret, intdiv(now()->timestamp, 30));
        $this->post(route('profile.two-factor.enable'), ['enable_password' => $password, 'setup_code' => $code])
            ->assertRedirectToRoute('profile');
        $this->assertStringNotContainsString($secret, $user->fresh()->getRawOriginal('two_factor'));
        $recoveryCode = Crypt::decrypt(session('recovery_codes'))[0];
        $this->get(route('profile'))->assertOk()->assertSee($recoveryCode);
        $this->get(route('profile'))->assertOk()->assertDontSee($recoveryCode);

        $this->post(route('logout'))->assertRedirectToRoute('login');
        $credentials = ['provider' => 'local', 'email' => $user->email, 'password' => $password];
        $this->post(route('login.submit'), $credentials)->assertRedirectToRoute('two-factor.challenge');
        $this->assertGuest();
        $this->get(route('two-factor.challenge'))->assertOk();
        $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => $recoveryCode])->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get(route('profile'))->assertOk();

        $this->post(route('logout'));
        $this->post(route('login.submit'), $credentials)->assertRedirectToRoute('two-factor.challenge');
        $this->post(route('two-factor.verify'), ['code' => $recoveryCode])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->travel(30)->seconds();
        $freshCode = $twoFactor->code($secret, intdiv(now()->timestamp, 30));
        $this->post(route('two-factor.verify'), ['code' => $freshCode])->assertRedirectToRoute('dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_ldap_group_authorization_creates_a_read_only_profile(): void
    {
        $this->withoutVite();
        $email = 'directory@example.test';
        $group = 'CN=NexoraIPAM,OU=Groupes,DC=example,DC=test';
        $settings = ['enabled' => true, 'group_dn' => $group];
        Setting::write('ldap', $settings);

        $this->mock(LdapLogin::class, function ($ldap) use ($settings, $email): void {
            $ldap->shouldReceive('verify')->once()->with($settings, $email, 'password')->andReturnNull();
            $ldap->shouldReceive('verify')->once()->with($settings, $email, 'directory-password')
                ->andReturn(['id' => str_repeat('a', 64), 'name' => 'Personne LDAP']);
        });

        $this->post(route('login.submit'), ['provider' => 'ldap', 'email' => $email, 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->post(route('login.submit'), ['provider' => 'ldap', 'email' => $email, 'password' => 'directory-password'])
            ->assertRedirectToRoute('dashboard');
        $this->assertDatabaseHas('users', ['email' => $email, 'provider' => 'ldap', 'role' => 'viewer']);
        $this->get(route('profile'))->assertOk()->assertSee('Annuaire LDAP')->assertDontSee('Changer mon mot de passe');
        $this->post(route('profile.rename'), ['name' => 'Modification'])->assertForbidden();
        $this->post(route('profile.two-factor.setup'))->assertForbidden();
        $this->get(route('password.change'))->assertForbidden();
    }

    public function test_totp_matches_the_rfc_sha1_vector(): void
    {
        $this->assertSame('287082', app(TwoFactor::class)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
    }
}
