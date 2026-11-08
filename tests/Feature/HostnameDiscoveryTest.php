<?php

namespace Tests\Feature;

use App\Jobs\DiscoverSubnet;
use App\Jobs\ImportDhcpLeases;
use App\Jobs\PollNetworkDevice;
use App\Livewire\Preferences;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\NetworkDevice;
use App\Models\Setting;
use App\Models\Subnet;
use App\Models\User;
use App\Support\Audit;
use App\Support\HostnameResolver;
use App\Support\NetworkDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeSnmpAgent;
use Tests\TestCase;

class HostnameDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_less_reliable_source_never_replaces_a_more_reliable_name(): void
    {
        $address = IpAddress::factory()->make(['resolved_hostname' => null]);

        $this->assertTrue(HostnameResolver::assign($address, 'PC-ACCUEIL', 'netbios'));
        $this->assertSame(['pc-accueil', 'netbios'], [$address->resolved_hostname, $address->hostname_source]);
        $this->assertFalse(HostnameResolver::assign($address, 'accueil.local', 'mdns'));
        $this->assertTrue(HostnameResolver::assign($address, 'pc-accueil.lan.example.test.', 'dhcp'));
        $this->assertSame(['pc-accueil.lan.example.test', 'dhcp'], [$address->resolved_hostname, $address->hostname_source]);
        $this->assertFalse(HostnameResolver::assign($address, '192.0.2.4', 'dns'));
        $this->assertFalse(HostnameResolver::assign($address, 'bad name', 'dns'));
    }

    public function test_discovery_falls_back_on_netbios_then_mdns_when_dns_has_no_name(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/29']);
        Process::fake([
            "*'ping'*'192.0.2.1'" => Process::result(),
            "*'ping'*'192.0.2.2'" => Process::result(),
            "*'ping'*'192.0.2.3'" => Process::result(),
            "*'ping'*" => Process::result(exitCode: 1),
            "*'getent'*'192.0.2.1'" => Process::result(output: "192.0.2.1 nas.example.test\n"),
            "*'getent'*" => Process::result(exitCode: 2),
            "*'nmblookup'*'192.0.2.2'" => Process::result(output: "Looking up status of 192.0.2.2\n\tWORKGROUP       <00> - <GROUP> B <ACTIVE>\n\tPC-COMPTA       <00> -         B <ACTIVE>\n\tPC-COMPTA       <20> -         B <ACTIVE>\n"),
            "*'nmblookup'*" => Process::result(output: 'No reply from 192.0.2.3', exitCode: 1),
            "*'dig'*'192.0.2.3'" => Process::result(output: "imprimante-rdc.local.\n"),
            "*'ip'*'neigh'*" => Process::result(output: '[]'),
        ]);
        $token = (string) Str::uuid();
        $subnet->forceFill(['scan_token' => $token, 'scan_status' => 'queued'])->save();
        app()->call([new DiscoverSubnet($subnet->id, $token), 'handle']);

        $names = IpAddress::orderBy('address_key')->get()->mapWithKeys(fn (IpAddress $address): array => [$address->address => [$address->resolved_hostname, $address->hostname_source]]);
        $this->assertSame([
            '192.0.2.1' => ['nas.example.test', 'dns'],
            '192.0.2.2' => ['pc-compta', 'netbios'],
            '192.0.2.3' => ['imprimante-rdc.local', 'mdns'],
        ], $names->all());
        $this->assertNull(NetworkDiscovery::netbiosName("\tWORKGROUP <00> - <GROUP> B <ACTIVE>\n"));
    }

    public function test_snmp_names_the_device_and_its_lldp_neighbours(): void
    {
        $agent = new FakeSnmpAgent;
        Process::fake(fn ($process) => Process::result(output: $agent->respond($process->command)));
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/24']);
        $self = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.2', 'resolved_hostname' => null]);
        $neighbour = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.254', 'resolved_hostname' => null]);
        $device = NetworkDevice::factory()->create(['host' => '192.0.2.2']);

        $token = (string) Str::uuid();
        $device->forceFill(['poll_token' => $token, 'poll_status' => 'queued'])->save();
        app()->call([new PollNetworkDevice($device->id, $token), 'handle']);

        $this->assertSame(['lab-sw01', 'snmp'], [$self->fresh()->resolved_hostname, $self->fresh()->hostname_source]);
        $this->assertSame(['core-sw01.example.test', 'lldp'], [$neighbour->fresh()->resolved_hostname, $neighbour->fresh()->hostname_source]);
    }

    public function test_dhcp_leases_from_kea_and_isc_name_addresses_and_record_their_mac(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/24']);
        $known = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.10', 'resolved_hostname' => 'pc-10.example.test', 'hostname_source' => 'dns']);
        $file = tempnam(sys_get_temp_dir(), 'leases');
        file_put_contents($file, <<<'LEASES'
            lease 192.0.2.30 {
              binding state active;
              hardware ethernet 00:11:22:33:44:30;
              client-hostname "laptop-marc";
            }
            lease 192.0.2.31 {
              binding state active;
              hardware ethernet 00:11:22:33:44:31;
            }
            lease 192.0.2.31 {
              binding state free;
            }
            LEASES);
        Setting::write('dhcp', ['enabled' => true, 'kea_url' => 'https://kea.example.test:8000/', 'kea_username' => 'ipam', 'kea_password' => 'kea-secret', 'leases_file' => $file, 'interval' => 15]);
        Http::fake(['https://kea.example.test:8000/*' => Http::response([['result' => 0, 'arguments' => ['leases' => [
            ['ip-address' => '192.0.2.10', 'hw-address' => '00:11:22:33:44:10', 'hostname' => 'pc-10', 'state' => 0],
            ['ip-address' => '192.0.2.20', 'hw-address' => '00-11-22-33-44-20', 'hostname' => 'phone-20.', 'state' => 0],
            ['ip-address' => '192.0.2.21', 'hw-address' => '00:11:22:33:44:21', 'hostname' => 'expired', 'state' => 2],
            ['ip-address' => '198.51.100.5', 'hw-address' => '00:11:22:33:44:99', 'hostname' => 'outside', 'state' => 0],
        ]]]])]);

        app()->call([new ImportDhcpLeases, 'handle']);

        Http::assertSent(fn ($request) => $request['command'] === 'lease4-get-all' && $request->hasHeader('Authorization'));
        $this->assertSame(['pc-10.example.test', 'dns', '00:11:22:33:44:10'], [$known->fresh()->resolved_hostname, $known->fresh()->hostname_source, $known->fresh()->mac_address]);
        $this->assertDatabaseHas('ip_addresses', ['address' => '192.0.2.20', 'resolved_hostname' => 'phone-20', 'hostname_source' => 'dhcp', 'mac_address' => '00:11:22:33:44:20']);
        $this->assertDatabaseHas('ip_addresses', ['address' => '192.0.2.30', 'resolved_hostname' => 'laptop-marc', 'mac_address' => '00:11:22:33:44:30']);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '192.0.2.31']);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '192.0.2.21']);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '198.51.100.5']);
        $this->assertDatabaseHas('address_observations', ['ip_address_id' => $known->id, 'source' => 'dhcp', 'mac_address' => '00:11:22:33:44:10']);
        $this->assertSame(['leases' => [null, 4], 'named' => [null, 2], 'created' => [null, 2]], AuditLog::where('action', 'dhcp.imported')->sole()->changes);
        $this->assertSame(4, Setting::read('dhcp_state')['leases']);
        unlink($file);
    }

    public function test_administrators_configure_dhcp_and_the_scheduler_respects_the_interval(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)->test(Preferences::class)
            ->call('importDhcp')->assertHasErrors('dhcp.enabled')
            ->set('dhcp.enabled', true)->call('save', 'dhcp')->assertHasErrors('dhcp.kea_url')
            ->set('dhcp.kea_url', 'https://kea.example.test:8000/')->set('dhcp.kea_password', 'kea-secret')->set('dhcp.interval', 30)
            ->call('save', 'dhcp')->assertHasNoErrors()->assertSet('dhcp.kea_password', '')
            ->call('importDhcp')->assertDispatched('nexora-toast', tone: 'success');

        $this->assertSame('kea-secret', Setting::read('dhcp')['kea_password']);
        $this->assertSame([null, Audit::MASK], AuditLog::where('action', 'settings.updated')->sole()->changes['kea_password']);
        Queue::assertPushedOn('discovery', ImportDhcpLeases::class);

        $this->artisan('nexora:import-dhcp')->assertSuccessful();
        Queue::assertPushed(ImportDhcpLeases::class, 1);
        $this->travel(31)->minutes();
        $this->artisan('nexora:import-dhcp')->assertSuccessful();
        Queue::assertPushed(ImportDhcpLeases::class, 2);
    }
}
