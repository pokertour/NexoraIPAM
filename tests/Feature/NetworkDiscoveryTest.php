<?php

namespace Tests\Feature;

use App\Livewire\Inventory;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Subnet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class NetworkDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_worker_discovers_new_hosts_and_preserves_manual_inventory(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/29']);
        $router = IpAddress::factory()->for($subnet)->create([
            'address' => '192.0.2.1', 'status' => 'reserved', 'hostname' => 'manual-router',
        ]);
        $unresponsive = IpAddress::factory()->for($subnet)->create([
            'address' => '192.0.2.2', 'status' => 'used', 'last_seen_at' => now()->subDay(),
        ]);

        Process::fake([
            "*'ping'*'192.0.2.1'" => Process::result(),
            "*'ping'*'192.0.2.3'" => Process::result(),
            "*'ping'*" => Process::result(exitCode: 1),
            "*'getent'*'192.0.2.1'" => Process::result(output: "192.0.2.1 router.example.test\n"),
            "*'getent'*'192.0.2.3'" => Process::result(output: "192.0.2.3 workstation.example.test\n"),
            "*'ip'*'neigh'*" => Process::result(output: '[{"dst":"192.0.2.3","lladdr":"00:11:22:33:44:55","state":["REACHABLE"]}]'),
        ])->preventStrayProcesses();

        Livewire::test(Inventory::class, ['section' => 'subnets'])->call('confirmScan', $subnet->id)
            ->call('scan')->assertDispatched('nexora-toast', tone: 'success', message: 'Découverte mise en file d’attente.')
            ->call('confirmScan', $subnet->id)->call('scan')->assertDispatched('nexora-toast', tone: 'info', message: 'Une découverte est déjà en cours pour ce réseau.');

        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'discovery', '--once' => true])->assertSuccessful();

        $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'scan_status' => 'completed', 'scan_targets' => 6, 'scan_responded' => 2]);
        $this->assertDatabaseHas('ip_addresses', ['id' => $router->id, 'hostname' => 'manual-router', 'resolved_hostname' => 'router.example.test', 'status' => 'reserved']);
        $this->assertDatabaseHas('ip_addresses', ['subnet_id' => $subnet->id, 'address' => '192.0.2.3', 'resolved_hostname' => 'workstation.example.test', 'mac_address' => '00:11:22:33:44:55', 'last_probe_responded' => true, 'status' => 'used']);
        $this->assertTrue($unresponsive->fresh()->last_probe_at->isAfter($unresponsive->fresh()->last_seen_at));
        $this->assertDatabaseCount('ip_addresses', 3);
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('ipv4States', ['used' => 2, 'reserved' => 1, 'available' => 3])
            ->assertViewHas('pingCounts', ['responding' => 2, 'unresponsive' => 1, 'unprobed' => 0]);
    }

    public function test_only_administrators_can_start_a_discovery(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $subnet = Subnet::factory()->create();

        Livewire::test(Inventory::class, ['section' => 'subnets'])->call('confirmScan', $subnet->id)->assertForbidden();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_the_scheduler_queues_only_due_direct_subnets_and_can_be_disabled(): void
    {
        $this->withoutVite();
        $this->freezeSecond();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $agency = Location::factory()->create();
        $office = Location::factory()->create(['kind' => 'office', 'parent_id' => $agency->id]);
        $direct = Subnet::factory()->create(['location_id' => $agency->id, 'cidr' => '192.0.2.1/32']);
        $other = Subnet::factory()->create(['location_id' => $office->id]);

        $settings = Livewire::test(Inventory::class, ['section' => 'locations'])->call('open', $agency->id)
            ->set('form.discovery_interval', 7)->call('save')->assertHasNoErrors();
        $this->assertTrue($agency->fresh()->next_discovery_at->equalTo(now()->addMinutes(7)));
        $this->artisan('nexora:discover-due')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);

        $this->travel(7)->minutes();
        $this->artisan('nexora:discover-due')->assertSuccessful();
        $this->artisan('nexora:discover-due')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('queued', $direct->fresh()->scan_status);
        $this->assertNull($other->fresh()->scan_status);

        $settings->call('open', $agency->id)->set('form.discovery_interval', '')->call('save')->assertHasNoErrors();
        $this->assertNull($agency->fresh()->next_discovery_at);
    }
}
