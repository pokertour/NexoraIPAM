<?php

namespace Tests\Feature;

use App\Console\Commands\InstallAdmin;
use App\Livewire\Accounts;
use App\Livewire\Inventory;
use App\Livewire\Preferences;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_prints_a_temporary_password_and_preserves_it_on_retry(): void
    {
        $this->withoutVite();
        $this->assertSame(0, Artisan::call('nexora:install'));
        $output = Artisan::output();
        $this->assertStringContainsString('admin@example.com', $output);
        $password = trim(str($output)->after('Mot de passe temporaire : ')->toString());
        $admin = User::whereEmail('admin@example.com')->sole();
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertSame('admin', $admin->role);

        $this->artisan('nexora:install')->expectsOutput('Le compte initial existe déjà.')->assertSuccessful();
        $this->assertTrue(Hash::check($password, $admin->fresh()->password));

        $this->get('/')->assertRedirectToRoute('login');
        $this->get(route('login'))->assertOk();
        $this->post(route('login.submit'), ['email' => $admin->email, 'provider' => 'local', 'password' => $password])
            ->assertRedirectToRoute('password.change');
        $this->get(route('subnets'))->assertRedirectToRoute('password.change');

        $this->post(route('password.update'), [
            'current_password' => $password,
            'password' => 'Personal-Password-2026!',
            'password_confirmation' => 'Personal-Password-2026!',
        ])->assertRedirectToRoute('dashboard');

        foreach (['dashboard', 'subnets', 'addresses', 'vlans', 'accounts', 'preferences'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_the_temporary_password_is_printed_as_is_whatever_its_characters(): void
    {
        $password = 'a<b>c\\>d<info>e</info>f\\';
        $this->app->bind(InstallAdmin::class, fn () => new class($password) extends InstallAdmin
        {
            public function __construct(private string $fixed)
            {
                parent::__construct();
            }

            protected function temporaryPassword(): string
            {
                return $this->fixed;
            }
        });

        $this->assertSame(0, Artisan::call('nexora:install'));
        $this->assertStringContainsString('Mot de passe temporaire : '.$password, Artisan::output());
        $this->assertTrue(Hash::check($password, User::whereEmail(InstallAdmin::EMAIL)->sole()->password));
    }

    public function test_a_renamed_installation_account_is_not_created_again_and_accounts_can_be_deleted(): void
    {
        $this->withoutVite();
        $this->assertSame(0, Artisan::call('nexora:install'));
        $install = User::whereEmail(InstallAdmin::EMAIL)->sole();
        $install->forceFill(['email' => 'systeme@example.test', 'must_change_password' => false])->save();

        $this->artisan('nexora:install')->expectsOutput('Le compte initial existe déjà.')->assertSuccessful();
        $this->assertSame(1, User::count(), 'Renaming the e-mail must not create a second installation account.');
        $this->assertTrue(InstallAdmin::isInstallAccount($install->fresh()));

        $other = User::factory()->create(['role' => 'admin', 'name' => 'Doublon']);
        $other->createToken('script');
        $this->actingAs($install);
        Livewire::test(Accounts::class)
            ->assertSeeHtml('wire:click="confirmDelete('.$other->id.')"')->assertDontSeeHtml('wire:click="confirmDelete('.$install->id.')"')
            ->call('confirmDelete', $install->id)->assertForbidden();
        Livewire::test(Accounts::class)->call('confirmDelete', $other->id)->assertSee('Supprimer le compte Doublon ?')
            ->call('delete')->assertDispatched('nexora-toast', tone: 'success');

        $this->assertModelMissing($other);
        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'deleted', 'subject_type' => 'user', 'subject_id' => $other->id]);

        $this->actingAs($other2 = User::factory()->create(['role' => 'admin']));
        Livewire::test(Accounts::class)->call('confirmDelete', $install->id)->assertForbidden();
        Livewire::test(Accounts::class)->call('confirmDelete', $other2->id)->assertForbidden();
    }

    public function test_the_upgrade_recognises_a_renamed_installation_account(): void
    {
        $renamed = User::factory()->create(['role' => 'admin', 'email' => 'systeme@example.test']);
        $recreated = User::factory()->create(['role' => 'admin', 'email' => InstallAdmin::EMAIL]);
        Setting::where('key', 'installation')->delete();

        (require database_path('migrations/2026_11_09_150445_record_installation_account.php'))->up();

        $this->assertTrue(InstallAdmin::isInstallAccount($renamed));
        $this->assertFalse(InstallAdmin::isInstallAccount($recreated));
    }

    public function test_a_reader_cannot_modify_inventory_or_access_admin_pages(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->get(route('subnets'))->assertOk()->assertDontSee('wire:click="open"', false);
        $this->get(route('accounts'))->assertForbidden();
        $this->get(route('preferences'))->assertForbidden();
        Livewire::test(Inventory::class, ['section' => 'subnets'])->call('open')->assertForbidden();
    }

    public function test_manual_account_creation_accepts_only_local_accounts(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $component = Livewire::test(Accounts::class)->call('open')
            ->set('form.name', 'Opérateur réseau')
            ->set('form.email', 'OPERATOR@example.com')
            ->set('form.role', 'operator')
            ->set('form.password', 'Temporary-password-42');

        $component->set('form.provider', 'oidc')->call('save')->assertHasErrors('form.provider');
        $component->set('form.provider', 'ldap')->call('save')->assertHasErrors('form.provider');
        $this->assertDatabaseMissing('users', ['email' => 'operator@example.com']);

        $component->set('form.provider', null)
            ->set('form.external_id', str_repeat('a', 64))->call('save')->assertHasErrors('form.external_id')
            ->set('form.external_id', null)->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'operator@example.com', 'role' => 'operator', 'provider' => 'local', 'external_id' => null,
        ]);
    }

    public function test_an_administrator_can_store_settings_without_returning_the_secret(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $settings = [
            'enabled' => true,
            'issuer' => 'https://idp.example.com',
            'client_id' => 'nexora',
            'secret' => 'a-confidential-client-secret',
        ];
        Livewire::test(Preferences::class)->set('oidc', $settings)->call('save', 'oidc')
            ->assertHasNoErrors()->assertSet('oidc.secret', '');

        $this->assertSame($settings + ['scopes' => 'openid profile email'], Setting::read('oidc'));
        $this->assertStringNotContainsString($settings['secret'], Setting::findOrFail('oidc')->getRawOriginal('value'));
        $this->get(route('preferences'))->assertOk()->assertDontSee($settings['secret']);
    }

    public function test_smtp_can_run_without_encryption_for_a_local_relay(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(Preferences::class)
            ->set('smtp.enabled', true)->set('smtp.host', 'relay.local')->set('smtp.port', 25)->set('smtp.from', 'ipam@example.test')
            ->set('smtp.scheme', 'ssl')->call('save', 'smtp')->assertHasErrors('smtp.scheme')
            ->set('smtp.scheme', 'none')->assertSee('Sans chiffrement, les messages')->call('save', 'smtp')->assertHasNoErrors();
        $this->assertSame('none', Setting::read('smtp')['scheme']);

        $transport = function (): EsmtpTransport {
            $this->app->forgetInstance('mail.manager');
            Mail::clearResolvedInstances();

            return Mail::mailer('smtp')->getSymfonyTransport();
        };
        $plain = $transport();
        $this->assertSame([false, false, 'relay.local', 25], [$plain->isAutoTls(), $plain->isTlsRequired(), $plain->getStream()->getHost(), $plain->getStream()->getPort()]);

        Setting::write('smtp', ['scheme' => 'smtp'] + Setting::read('smtp'));
        $starttls = $transport();
        $this->assertSame([true, true], [$starttls->isAutoTls(), $starttls->isTlsRequired()]);
    }

    public function test_about_compares_the_installed_version_and_caches_the_latest_release(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        config(['app.version' => 'v1.2.3']);
        Http::preventStrayRequests();
        Http::fake(['https://api.github.com/repos/pokertour/NexoraIPAM/releases/latest' => Http::response(['tag_name' => 'v1.10.0'])]);

        $this->get(route('about'))->assertOk()->assertSee('Mise à jour disponible')->assertSee('v1.2.3');
        config(['app.version' => 'v1.10.0']);
        $this->get(route('about'))->assertOk()->assertSee('À jour');
        config(['app.version' => 'local-dev']);
        $this->get(route('about'))->assertOk()->assertSee('local-dev')->assertSee('v1.10.0')->assertDontSee('Mise à jour disponible');
        Http::assertSentCount(1);
    }

    public function test_browser_preferences_select_english_and_fall_back_to_french(): void
    {
        $this->withoutVite();
        $this->withHeader('Accept-Language', 'de-DE,en-GB;q=0.9,fr;q=0.5');
        $this->get(route('login'))->assertOk()->assertSeeHtml('<html lang="en">')->assertSee('Sign in')
            ->assertSeeHtml('value="en" lang="en" class="locale-option" aria-current="true"');
        $this->post(route('login.submit'), ['provider' => 'local', 'email' => 'unknown@example.com', 'password' => 'incorrect'])
            ->assertSessionHasErrors(['login' => 'Invalid credentials or account not authorized.']);

        $this->withHeader('Accept-Language', 'de-DE,es;q=0.9');
        $this->get(route('login'))->assertOk()->assertSeeHtml('<html lang="fr">')->assertSee('Se connecter');
        $this->post(route('login.submit'), ['provider' => 'local'])
            ->assertSessionHasErrors(['email' => 'Le champ adresse e-mail est obligatoire.']);
    }

    public function test_navbar_language_choice_overrides_the_browser(): void
    {
        $this->withoutVite();
        $this->withHeader('Accept-Language', 'fr-FR,en;q=0.5');

        $this->post(route('locale.update'), ['locale' => 'en', 'return_to' => '/auth?source=navbar'])
            ->assertStatus(303)->assertHeader('Location', '/auth?source=navbar')->assertCookie('nexora-locale', 'en');

        $this->withCookie('nexora-locale', 'en')->get(route('login'))->assertOk()
            ->assertSeeHtml('<html lang="en">')->assertSee('Language')
            ->assertSeeHtml('value="en" lang="en" class="locale-option" aria-current="true"')->assertDontSeeHtml('value="auto"');

        $this->actingAs(User::factory()->create())->get(route('profile'))->assertOk()
            ->assertSeeHtml('<html lang="en">')->assertSeeHtml('id="navbar-locale"');

        $this->post(route('locale.update'), ['locale' => 'fr', 'return_to' => '//example.com'])
            ->assertRedirect(route('dashboard'))->assertCookie('nexora-locale', 'fr');

        $this->postJson(route('locale.update'), ['locale' => 'es'])->assertUnprocessable();
    }

    public function test_english_is_used_in_the_workspace_and_livewire_actions(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $headers = ['Accept-Language' => 'en-US,fr;q=0.5'];

        $this->withHeaders($headers)->get(route('accounts'))->assertOk()
            ->assertSeeHtml('<html lang="en">')->assertSee('My account')->assertSee('Add a local account');

        Livewire::withHeaders($headers)->test(Accounts::class)->call('open')
            ->assertSee('Add a local account')
            ->set('form.name', 'Network Operator')->set('form.email', 'operator@example.test')
            ->set('form.password', 'Temporary-password-42')
            ->call('save')->assertDispatched('nexora-toast', tone: 'success', message: 'Account saved.');
    }

    public function test_accounts_list_shows_the_source_badge_filters_and_last_login(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin local']);
        $ldap = User::factory()->create(['name' => 'Alice Annuaire', 'provider' => 'ldap', 'external_id' => 'cn=alice']);
        User::factory()->create(['name' => 'Bob Sso', 'provider' => 'oidc', 'external_id' => 'bob-sub']);
        AuditLog::forceCreate(['user_id' => $ldap->id, 'action' => 'auth.login', 'subject_type' => 'user', 'subject_id' => $ldap->id, 'subject_label' => $ldap->name, 'created_at' => now()->subHour()]);
        $this->actingAs($admin);

        Livewire::test(Accounts::class)
            ->assertSee('Alice Annuaire')->assertSee('Bob Sso')
            ->assertSeeHtml('<span class="badge tone-blue">LDAP</span>')
            ->assertSeeHtml('<span class="badge tone-violet">OpenID Connect</span>')
            ->assertSee('Jamais')
            ->set('source', 'ldap')->assertSee('Alice Annuaire')->assertDontSee('Bob Sso')->assertDontSee('Admin local')
            ->set('source', '')->set('search', 'bob')->assertSee('Bob Sso')->assertDontSee('Alice Annuaire');
    }
}
