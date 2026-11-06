<?php

namespace Tests\Feature;

use App\Livewire\Accounts;
use App\Livewire\Inventory;
use App\Livewire\Preferences;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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

        $this->assertSame($settings, Setting::read('oidc'));
        $this->assertStringNotContainsString($settings['secret'], Setting::findOrFail('oidc')->getRawOriginal('value'));
        $this->get(route('preferences'))->assertOk()->assertDontSee($settings['secret']);
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
}
