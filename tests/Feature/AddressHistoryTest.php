<?php

namespace Tests\Feature;

use App\Jobs\DiscoverSubnet;
use App\Livewire\Inventory;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\User;
use App\Support\AddressHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class AddressHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_builds_a_timeline_of_mac_addresses_and_host_names(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/30']);
        $this->discover($subnet, '00:11:22:33:44:55', 'printer.example.test');
        $this->discover($subnet, '00:11:22:33:44:55', 'printer.example.test');
        $this->discover($subnet, '66:77:88:99:aa:bb', 'laptop.example.test');

        $address = IpAddress::where('address', '192.0.2.1')->sole();
        $observations = $address->observations()->orderBy('id')->get();
        $this->assertSame(['00:11:22:33:44:55', '66:77:88:99:aa:bb'], $observations->pluck('mac_address')->all());
        $this->assertSame(2, $observations[0]->times_seen);
        $this->assertSame('icmp', $observations[1]->source);
    }

    public function test_an_unknown_mac_keeps_the_current_observation(): void
    {
        $address = IpAddress::factory()->create();
        $history = app(AddressHistory::class);

        $this->assertNull($history->observe($address, '00:11:22:33:44:55', null, 'icmp', now()));
        $this->assertNull($history->observe($address, null, null, 'icmp', now()));
        $this->assertSame('00:11:22:33:44:55', $history->observe($address, '00:11:22:33:44:66', null, 'snmp', now()));
        $this->assertSame(2, $address->observations()->count());
    }

    public function test_viewers_open_the_history_with_observations_and_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $address = IpAddress::factory()->create(['hostname' => 'old-name']);
        $address->update(['hostname' => 'new-name']);
        app(AddressHistory::class)->observe($address, '00:11:22:33:44:55', 'seen.example.test', 'icmp', now());

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(Inventory::class, ['section' => 'addresses'])
            ->call('showHistory', $address->id)
            ->assertSee('00:11:22:33:44:55')->assertSee('seen.example.test')
            ->assertSee('old-name')->assertSee('new-name');
    }

    private ?string $mac = null;

    private ?string $hostname = null;

    private function discover(Subnet $subnet, string $mac, string $hostname): void
    {
        [$this->mac, $this->hostname] = [$mac, $hostname];
        Process::fake(fn ($process) => match (true) {
            $process->command[0] === 'ping' => Process::result(exitCode: in_array('192.0.2.1', $process->command, true) ? 0 : 1),
            $process->command[0] === 'getent' => Process::result(output: "192.0.2.1 {$this->hostname}\n"),
            default => Process::result(output: '[{"dst":"192.0.2.1","lladdr":"'.$this->mac.'","state":["REACHABLE"]}]'),
        });
        $subnet->refresh()->forceFill(['scan_token' => 'token', 'scan_status' => 'queued'])->save();
        app()->call([new DiscoverSubnet($subnet->id, 'token'), 'handle']);
    }
}
