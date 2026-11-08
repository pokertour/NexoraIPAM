<?php

namespace Tests\Feature;

use App\Livewire\Inventory;
use App\Models\DevicePort;
use App\Models\DevicePortMac;
use App\Models\IpAddress;
use App\Models\NetworkDevice;
use App\Models\Subnet;
use App\Models\User;
use App\Support\AddressHistory;
use App\Support\AlertMonitor;
use App\Support\MacAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_addresses_can_be_filtered_by_status_and_last_ping(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/24']);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.1', 'status' => 'reserved', 'last_probe_at' => now(), 'last_probe_responded' => true]);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.2', 'status' => 'used', 'last_probe_at' => now(), 'last_probe_responded' => false]);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.3', 'status' => 'used', 'last_probe_at' => now()->subHour(), 'last_seen_at' => null]);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.4', 'status' => 'available']);

        $listed = fn (array $filters): array => Livewire::withQueryParams($filters)->test(Inventory::class, ['section' => 'addresses'])
            ->viewData('records')->pluck('address')->all();

        $this->assertSame(['192.0.2.1'], $listed(['status' => 'reserved']));
        $this->assertSame(['192.0.2.2', '192.0.2.3'], $listed(['status' => 'used']));
        $this->assertSame(['192.0.2.1'], $listed(['ping' => 'responding']));
        $this->assertSame(['192.0.2.2', '192.0.2.3'], $listed(['ping' => 'unresponsive']));
        $this->assertSame(['192.0.2.4'], $listed(['ping' => 'unprobed']));
        $this->assertSame(['192.0.2.2', '192.0.2.3'], $listed(['ping' => 'unresponsive', 'status' => 'used']));
    }

    public function test_the_dashboard_links_its_figures_and_shows_recent_activity(): void
    {
        $this->withoutVite();
        $this->mock(MacAddress::class, fn (MockInterface $mock) => $mock->shouldReceive('vendor')->andReturn('Acme Networks'));
        $subnet = Subnet::factory()->create(['name' => 'Bureaux', 'cidr' => '192.0.2.0/24']);
        $history = app(AddressHistory::class);

        $old = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.10', 'mac_address' => '00:11:22:33:44:01']);
        $history->observe($old, '00:11:22:33:44:01', null, 'icmp', now()->subDays(30));
        $newcomer = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.20', 'hostname' => 'imprimante-rdc', 'mac_address' => '00:11:22:33:44:02']);
        $history->observe($newcomer, '00:11:22:33:44:02', null, 'icmp', now()->subHours(2));
        $moved = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.30', 'mac_address' => '00:11:22:33:44:01']);
        $history->observe($moved, '00:11:22:33:44:01', null, 'snmp', now()->subHour());

        $switch = NetworkDevice::factory()->create(['name' => 'sw-rdc', 'polled_at' => now(), 'poll_status' => 'completed']);
        NetworkDevice::factory()->create(['name' => 'sw-cave', 'poll_status' => 'failed', 'poll_error' => 'Délai dépassé']);
        $port = DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 3, 'name' => 'ether3', 'physical' => true, 'speed_mbps' => 1000, 'in_bps' => 900_000_000, 'out_bps' => 1_000_000]);
        DevicePortMac::create(['network_device_id' => $switch->id, 'device_port_id' => $port->id, 'mac_address' => '00:11:22:33:44:02', 'seen_at' => now()]);
        app(AlertMonitor::class)->raise('mac_changed', $moved, ['old' => 'aa:aa:aa:aa:aa:aa', 'new' => '00:11:22:33:44:01'], 'critical');

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $response = $this->get(route('dashboard'))->assertOk()
            ->assertSeeHtml('href="'.route('addresses', ['status' => 'reserved']).'"')
            ->assertSeeHtml('href="'.route('addresses', ['ping' => 'unprobed']).'"')
            ->assertSee('Nouveaux hôtes')->assertSee('imprimante-rdc')->assertSee('Acme Networks')->assertSee('sw-rdc ether3')
            ->assertSee('1 / 2')->assertSee('sw-cave')->assertSee('Délai dépassé')
            ->assertSee('Ports les plus chargés')->assertSee('90,0 %')
            ->assertSee('Changements de MAC récents')
            ->assertDontSee('Dernières modifications');

        $hosts = $response->viewData('newHosts')->keyBy(fn (array $host): string => $host['address']->address);
        $this->assertSame(['192.0.2.30', '192.0.2.20'], $hosts->keys()->all());
        $this->assertTrue($hosts['192.0.2.20']['new_mac']);
        $this->assertFalse($hosts['192.0.2.30']['new_mac']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('dashboard'))->assertOk()->assertSee('Dernières modifications');
    }
}
