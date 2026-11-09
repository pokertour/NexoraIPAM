<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\LdapLogin;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\TransportInterface;
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
        $this->get(route('profile'))->assertOk()->assertSee('Ce compte est géré par LDAP')->assertDontSee('Nouvelle adresse e-mail')->assertDontSee('Enregistrer le mot de passe');
        $this->post(route('profile.rename'), ['name' => 'Modification'])->assertForbidden();
        $this->post(route('profile.two-factor.setup'))->assertForbidden();
        $this->get(route('password.change'))->assertForbidden();
    }

    public function test_totp_matches_the_rfc_sha1_vector(): void
    {
        $this->assertSame('287082', app(TwoFactor::class)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
    }

    public function test_a_local_account_changes_its_email_after_confirming_the_new_address(): void
    {
        $this->withoutVite();
        $mail = $this->captureMail();
        $user = User::factory()->create(['email' => 'old@example.test', 'password' => 'Initial-password-42']);
        User::factory()->create(['email' => 'taken@example.test']);
        $this->actingAs($user);

        $this->post(route('profile.email'), ['email' => 'new@example.test', 'email_password' => 'wrong-password'])->assertSessionHasErrorsIn('email', 'email_password');
        $this->post(route('profile.email'), ['email' => 'OLD@example.test', 'email_password' => 'Initial-password-42'])->assertSessionHasErrorsIn('email', 'email');
        $this->post(route('profile.email'), ['email' => 'taken@example.test', 'email_password' => 'Initial-password-42'])->assertSessionHasErrorsIn('email', 'email');
        $this->assertCount(0, $mail->messages());

        $this->post(route('profile.email'), ['email' => ' New@Example.test ', 'email_password' => 'Initial-password-42'])
            ->assertRedirectToRoute('profile')->assertSessionHas('notice', 'Un lien de confirmation a été envoyé à new@example.test.');
        $this->assertSame(['old@example.test', 'new@example.test'], [$user->fresh()->email, $user->fresh()->pending_email]);
        $this->assertSame(['pending_email' => [null, 'new@example.test']], AuditLog::where('action', 'email.change_requested')->sole()->changes);
        $this->get(route('profile'))->assertSee('En attente de confirmation')->assertSee('new@example.test');

        $message = $mail->messages()->sole()->getOriginalMessage();
        $this->assertSame('new@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame(1, preg_match('#https?://\S+/profile/email/verify/\S+#', $message->getTextBody(), $link));

        $this->get($link[0])->assertRedirectToRoute('profile')->assertSessionHas('notice', 'Adresse e-mail mise à jour.');
        $user->refresh();
        $this->assertSame(['new@example.test', null], [$user->email, $user->pending_email]);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(['old@example.test', 'new@example.test'], AuditLog::where('action', 'updated')->where('subject_type', 'user')->where('subject_id', $user->id)->latest('id')->first()->changes['email']);
        $warning = $mail->messages()->last()->getOriginalMessage();
        $this->assertSame('old@example.test', $warning->getTo()[0]->getAddress());
        $this->assertStringContainsString('new@example.test', $warning->getTextBody());

        $this->get($link[0])->assertRedirectToRoute('profile')->assertSessionHasErrorsIn('email', 'email');
        $this->assertSame('new@example.test', $user->fresh()->email);
    }

    public function test_confirmation_links_expire_and_belong_to_their_account(): void
    {
        $mail = $this->captureMail();
        $user = User::factory()->create(['email' => 'old@example.test', 'password' => 'Initial-password-42']);
        $this->actingAs($user)->post(route('profile.email'), ['email' => 'new@example.test', 'email_password' => 'Initial-password-42']);
        preg_match('#https?://\S+/profile/email/verify/\S+#', $mail->messages()->sole()->getOriginalMessage()->getTextBody(), $link);

        $this->actingAs(User::factory()->create())->get($link[0])->assertForbidden();
        $this->actingAs($user)->get(str_replace('hash=', 'hash=0', $link[0]))->assertSessionHasErrorsIn('email', 'email');
        $this->travel(61)->minutes();
        $this->actingAs($user)->get($link[0])->assertSessionHasErrorsIn('email', 'email');
        $this->assertSame(['old@example.test', 'new@example.test'], [$user->fresh()->email, $user->fresh()->pending_email]);

        $this->post(route('profile.email.cancel'))->assertSessionHas('notice', 'Changement d’adresse annulé.');
        $this->assertNull($user->fresh()->pending_email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'email.change_cancelled', 'subject_id' => $user->id]);
    }

    public function test_email_changes_need_smtp_and_a_local_account(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['password' => 'Initial-password-42']);
        $this->actingAs($user)->get(route('profile'))->assertSee('L’envoi d’e-mails n’est pas configuré');
        $this->post(route('profile.email'), ['email' => 'new@example.test', 'email_password' => 'Initial-password-42'])->assertSessionHasErrorsIn('email', 'email');
        $this->assertNull($user->fresh()->pending_email);

        Setting::write('oidc', ['enabled' => true]);
        $this->actingAs(User::factory()->create(['provider' => 'oidc', 'external_id' => 'sub-1']))
            ->post(route('profile.email'), ['email' => 'new@example.test', 'email_password' => 'x'])->assertForbidden();
    }

    public function test_the_profile_lists_recent_sign_ins(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        AuditLog::forceCreate(['user_id' => $user->id, 'action' => 'auth.login', 'subject_type' => 'user', 'subject_id' => $user->id, 'ip_address' => '198.51.100.7', 'created_at' => now()->subDay()]);
        AuditLog::forceCreate(['action' => 'auth.failed', 'subject_type' => 'user', 'subject_id' => $user->id, 'ip_address' => '203.0.113.9', 'created_at' => now()->subHour()]);

        $this->actingAs($user)->get(route('profile'))->assertOk()
            ->assertSee('198.51.100.7')->assertSee('203.0.113.9')->assertSee('Échouée')->assertSee('Réussie');
    }

    /** Sends the SMTP mailer to an in-memory transport. */
    private function captureMail(): TransportInterface
    {
        Setting::write('smtp', ['enabled' => true, 'scheme' => 'smtp', 'host' => 'smtp.example.test', 'port' => 25, 'from' => 'ipam@example.test']);
        app('mail.manager');
        config(['mail.mailers.smtp' => ['transport' => 'array']]);
        Mail::purge('smtp');

        return Mail::mailer('smtp')->getSymfonyTransport();
    }
}
