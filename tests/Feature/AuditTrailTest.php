<?php

namespace Tests\Feature;

use App\Livewire\Accounts;
use App\Livewire\AuditTrail;
use App\Livewire\Inventory;
use App\Livewire\Preferences;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_changes_are_recorded_with_their_author_and_differences(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'name' => 'Opératrice']);
        $this->actingAs($operator);

        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('open')->set('form.name', 'Serveurs')->set('form.cidr', '192.0.2.0/24')->call('save')->assertHasNoErrors();
        $subnet = Subnet::sole();

        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('open', $subnet->id)->set('form.name', 'Serveurs Lyon')->call('save')->assertHasNoErrors()
            ->call('confirmDelete', $subnet->id)->call('delete');

        $entries = AuditLog::where('subject_type', 'subnet')->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $entries->pluck('action')->all());
        $this->assertSame([null, '192.0.2.0/24'], $entries[0]->changes['cidr']);
        $this->assertSame(['name' => ['Serveurs', 'Serveurs Lyon']], $entries[1]->changes);
        $this->assertSame('Opératrice', $entries[2]->actor);
        $this->assertSame('Serveurs Lyon · 192.0.2.0/24', $entries[2]->subject_label);
    }

    public function test_automatic_probe_fields_do_not_flood_the_trail(): void
    {
        $address = IpAddress::factory()->create();
        $before = AuditLog::count();

        $address->forceFill(['last_probe_at' => now(), 'last_probe_responded' => true])->save();

        $this->assertSame($before, AuditLog::count());
    }

    public function test_security_events_are_recorded_and_secrets_are_masked(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test', 'password' => 'correct-horse-battery']);

        $this->post(route('login.submit'), ['email' => 'admin@example.test', 'password' => 'wrong-password', 'provider' => 'local']);
        $this->post(route('login.submit'), ['email' => 'admin@example.test', 'password' => 'correct-horse-battery', 'provider' => 'local']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed', 'subject_label' => 'admin@example.test', 'user_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $admin->id]);

        $viewer = User::factory()->create(['provider' => 'local']);
        Livewire::actingAs($admin)->test(Accounts::class)
            ->call('open', $viewer->id)->set('form.password', 'a-new-temporary-password')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'password.changed', 'subject_id' => $viewer->id]);
        $this->assertStringNotContainsString('a-new-temporary-password', AuditLog::pluck('changes')->toJson());

        Livewire::actingAs($admin)->test(Preferences::class)
            ->set('smtp.enabled', true)->set('smtp.host', 'smtp.example.test')->set('smtp.port', 465)
            ->set('smtp.from', 'ipam@example.test')->set('smtp.password', 'smtp-secret')->call('save', 'smtp')->assertHasNoErrors();
        $settings = AuditLog::where('action', 'settings.updated')->sole();
        $this->assertSame('smtp', $settings->subject_label);
        $this->assertSame([null, Audit::MASK], $settings->changes['password']);
        $this->assertSame([null, 'smtp.example.test'], $settings->changes['host']);
    }

    public function test_only_administrators_can_read_and_filter_the_trail(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => 'admin']);
        Audit::record('created', Subnet::factory()->create(['name' => 'Réseau audité']));
        Audit::record('auth.logout', $admin, actor: $admin);

        $this->actingAs(User::factory()->create(['role' => 'operator']))->get(route('audit'))->assertForbidden();
        $this->actingAs($admin)->get(route('audit'))->assertOk()->assertSee('Réseau audité')->assertSee('Déconnexion');

        Livewire::actingAs($admin)->test(AuditTrail::class)
            ->set('action', 'auth.logout')->assertDontSee('Réseau audité')
            ->set('action', '')->set('search', 'audité')->assertSee('Réseau audité');
    }
}
