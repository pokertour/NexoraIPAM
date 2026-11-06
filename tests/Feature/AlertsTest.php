<?php

namespace Tests\Feature;

use App\Jobs\DiscoverSubnet;
use App\Livewire\Alerts;
use App\Livewire\Inventory;
use App\Models\Alert;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\Subnet;
use App\Models\User;
use App\Support\AddressHistory;
use App\Support\AlertMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_capacity_alerts_open_and_resolve_with_the_inventory(): void
    {
        Setting::write('alerts', ['enabled' => false, 'recipients' => '', 'threshold' => 50, 'conflicts' => true, 'host_down' => true]);
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $subnet = Subnet::factory()->create(['name' => 'Petit réseau', 'cidr' => '192.0.2.0/29']);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.1']);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.2']);

        Livewire::test(Inventory::class, ['section' => 'addresses'])
            ->call('open')->set('form.subnet_id', $subnet->id)->set('form.address', '192.0.2.3')->call('save')->assertHasNoErrors();

        $alert = Alert::sole();
        $this->assertSame('capacity', $alert->type);
        $this->assertSame(50, $alert->details['usage']);
        $this->assertStringContainsString('Petit réseau', $alert->message());
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.opened', 'subject_type' => 'alert', 'subject_id' => $alert->id]);

        Livewire::test(Inventory::class, ['section' => 'addresses'])
            ->call('confirmDelete', IpAddress::where('address', '192.0.2.3')->value('id'))->call('delete');
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_discovery_reports_conflicts_mac_changes_and_hosts_that_disappear(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/29']);
        $available = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.1', 'status' => 'available']);
        $server = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.2', 'status' => 'used']);
        $printer = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.3', 'status' => 'used', 'last_probe_responded' => true]);
        app(AddressHistory::class)->observe($server, '00:00:00:00:00:01', null, 'icmp', now()->subDay());

        Process::fake([
            "*'ping'*'192.0.2.1'" => Process::result(),
            "*'ping'*'192.0.2.2'" => Process::result(),
            "*'ping'*'192.0.2.4'" => Process::result(),
            "*'ping'*" => Process::result(exitCode: 1),
            "*'getent'*" => Process::result(exitCode: 2),
            "*'ip'*'neigh'*" => Process::result(output: '[{"dst":"192.0.2.2","lladdr":"00:00:00:00:00:02","state":["REACHABLE"]}]'),
        ]);
        $subnet->forceFill(['scan_token' => 'token', 'scan_status' => 'queued'])->save();
        app()->call([new DiscoverSubnet($subnet->id, 'token'), 'handle']);

        $alerts = Alert::orderBy('id')->get()->mapWithKeys(fn (Alert $alert): array => [$alert->type => $alert->subject_label]);
        $this->assertSame('192.0.2.1', $alerts['status_conflict']);
        $this->assertSame('192.0.2.2', $alerts['mac_changed']);
        $this->assertSame('192.0.2.3', $alerts['host_down']);
        $this->assertSame('192.0.2.4', $alerts['unknown_host']);
        $this->assertSame('critical', Alert::where('type', 'mac_changed')->value('severity'));
        $this->assertSame(['old' => '00:00:00:00:00:01', 'new' => '00:00:00:00:00:02'], Alert::where('type', 'mac_changed')->value('details'));
        $this->assertNotNull($available->fresh());
        $this->assertSame(4, Alert::count());
    }

    public function test_new_alerts_are_emailed_once_to_the_recipients(): void
    {
        Mail::fake();
        Setting::write('smtp', ['enabled' => true, 'scheme' => 'smtps', 'host' => 'smtp.example.test', 'port' => 465, 'from' => 'ipam@example.test']);
        Setting::write('alerts', ['enabled' => true, 'recipients' => 'noc@example.test', 'threshold' => 80, 'conflicts' => true, 'host_down' => true]);
        $address = IpAddress::factory()->create();
        $monitor = app(AlertMonitor::class);

        $monitor->raise('host_down', $address);
        $monitor->raise('host_down', $address);

        $this->assertSame(1, Alert::count());
        $this->assertNotNull(Alert::sole()->notified_at);
    }

    public function test_operators_acknowledge_alerts_and_viewers_only_read_them(): void
    {
        $this->withoutVite();
        $alert = app(AlertMonitor::class)->raise('host_down', IpAddress::factory()->create(['address' => '192.0.2.77']));

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->get(route('dashboard'))->assertOk()->assertSee('Alertes actives')->assertSee('192.0.2.77')->assertSee('1 alerte active');
        Livewire::test(Alerts::class)->assertSee('192.0.2.77')->call('acknowledge', $alert->id)->assertForbidden();

        $operator = User::factory()->create(['role' => 'operator', 'name' => 'Nina']);
        Livewire::actingAs($operator)->test(Alerts::class)->call('acknowledge', $alert->id)
            ->assertDontSee('192.0.2.77')->set('state', 'acknowledged')->assertSee('192.0.2.77')->assertSee('acquittée par Nina');
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.acknowledged', 'user_id' => $operator->id]);
    }
}
