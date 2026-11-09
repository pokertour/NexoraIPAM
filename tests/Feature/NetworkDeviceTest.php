<?php

namespace Tests\Feature;

use App\Jobs\PollNetworkDevice;
use App\Livewire\DeviceDetail;
use App\Livewire\Inventory;
use App\Livewire\NetworkDevices;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\DevicePort;
use App\Models\IpAddress;
use App\Models\NetworkDevice;
use App\Models\ScanRun;
use App\Models\Subnet;
use App\Models\User;
use App\Support\Audit;
use App\Support\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeSnmpAgent;
use Tests\TestCase;

class NetworkDeviceTest extends TestCase
{
    use RefreshDatabase;

    private FakeSnmpAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new FakeSnmpAgent;
        Process::fake(fn ($process) => $this->agent->online
            ? Process::result(output: $this->agent->respond($process->command))
            : Process::result(errorOutput: 'Timeout: No Response from 192.0.2.2:161.', exitCode: 1));
    }

    public function test_administrators_add_devices_with_encrypted_credentials_and_a_first_poll(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'operator']))->get(route('devices'))->assertOk();
        Livewire::test(NetworkDevices::class)->call('open')->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)->test(NetworkDevices::class)
            ->call('open')->set('form.name', 'Switch RDC')->set('form.host', 'not a host!')->call('save')->assertHasErrors(['form.host', 'form.community'])
            ->set('form.host', '192.0.2.2')->set('form.community', 'r34d-only')->call('save')->assertHasNoErrors()
            ->assertDispatched('nexora-toast', tone: 'success');

        $device = NetworkDevice::sole();
        $this->assertSame(['community' => 'r34d-only'], $device->credentials);
        $this->assertStringNotContainsString('r34d-only', (string) $device->getRawOriginal('credentials'));
        $this->assertSame('queued', $device->poll_status);
        $this->assertDatabaseCount('jobs', 1);
        $created = AuditLog::where('action', 'created')->where('subject_type', 'network_device')->sole();
        $this->assertSame([null, Audit::MASK], $created->changes['credentials']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.queued', 'subject_id' => $device->id]);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->assertSet('form.community', '')->set('form.name', 'Switch RDC 1')->call('save')->assertHasNoErrors();
        $this->assertSame(['community' => 'r34d-only'], $device->fresh()->credentials);
    }

    public function test_polling_reads_ports_vlans_macs_neighbours_and_the_arp_table(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/24']);
        $known = IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.11', 'hostname' => 'poste-101']);
        $device = $this->poll(NetworkDevice::factory()->create(['name' => 'sw-rdc']));

        $this->assertSame('completed', $device->poll_status);
        $this->assertSame('lab-sw01', $device->sys_name);
        $this->assertSame(123456789, $device->sys_uptime);
        $this->assertSame(30, $device->ports()->count());
        $this->assertSame(28, $device->ports()->where('physical', true)->count());

        $access = $device->ports()->where('name', 'GigabitEthernet1/0/1')->sole();
        $this->assertSame([1, 1, 1000, 10, 'Bureau 101 - poste'], [$access->oper_status, $access->admin_status, $access->speed_mbps, $access->pvid, $access->alias]);
        $this->assertNull($access->tagged_vlans);
        $this->assertSame('disabled', $device->ports()->where('name', 'GigabitEthernet1/0/23')->sole()->state());
        $this->assertSame(100, $device->ports()->where('name', 'GigabitEthernet1/0/5')->value('speed_mbps'));

        $uplink = $device->ports()->where('name', 'TenGigabitEthernet1/1/1')->sole();
        $this->assertSame([10, 20, 30], $uplink->tagged_vlans);
        $this->assertSame(['core-sw01.example.test', 'TenGigabitEthernet1/0/48'], [$uplink->neighbor_name, $uplink->neighbor_port]);
        $this->assertSame([10, 20], $device->ports()->where('name', 'TenGigabitEthernet1/1/2')->sole()->tagged_vlans);
        $this->assertSame([1], $uplink->untagged_vlans);
        $this->assertSame([10], $access->untagged_vlans);

        $this->assertSame(['rj45', 'sfp', 'sfp'], [$access->media, $device->ports()->where('name', 'GigabitEthernet1/0/24')->value('media'), $uplink->media]);
        $this->assertNull($device->ports()->where('name', 'Vlan10')->value('media'));
        $this->assertSame('00:1b:54:00:03:e9', $access->mac_address);

        $server = $device->ports()->where('name', 'GigabitEthernet1/0/13')->sole();
        $this->assertSame(3, $server->mac_count);
        $this->assertSame(20, $server->macs()->where('mac_address', '00:15:5d:00:13:01')->value('vlan'));
        $this->assertSame('192.0.2.50', $server->macs()->where('mac_address', '00:15:5d:00:13:01')->value('ip_address'));

        $this->assertSame('00:1a:2b:3c:4d:01', $known->fresh()->mac_address);
        $this->assertDatabaseHas('address_observations', ['ip_address_id' => $known->id, 'source' => 'snmp', 'location' => 'sw-rdc']);
        $this->assertDatabaseHas('ip_addresses', ['address' => '192.0.2.13', 'mac_address' => '00:1a:2b:3c:4d:03', 'subnet_id' => $subnet->id]);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '203.0.113.9']);
        $this->assertSame(2, Alert::where('type', 'unknown_host')->count());
        $this->assertSame(['ports' => [null, 28], 'up' => [null, 18], 'macs' => [null, 8], 'arp' => [null, 4], 'addresses' => [null, 3]],
            AuditLog::where('action', 'device.polled')->sole()->changes);
    }

    public function test_mikrotik_vlans_come_from_the_current_vlan_table(): void
    {
        $this->agent->asMikrotik();
        $device = $this->poll(NetworkDevice::factory()->create());
        $ports = $device->ports()->get()->keyBy('name');

        $this->assertSame('completed', $device->poll_status);
        $this->assertSame(['rj45', 'sfp', 'sfp', 'qsfp'], [$ports['ether1']->media, $ports['sfp-sfpplus1']->media, $ports['sfp-sfpplus2']->media, $ports['qsfpplus1-1']->media]);
        $this->assertFalse($ports['bridge']->physical);
        $this->assertSame([10, [10], null], [$ports['ether1']->pvid, $ports['ether1']->untagged_vlans, $ports['ether1']->tagged_vlans]);
        $this->assertSame([10, 20], $ports['sfp-sfpplus1']->tagged_vlans);
        $this->assertSame([20, [20], null], [$ports['sfp-sfpplus2']->pvid, $ports['sfp-sfpplus2']->untagged_vlans, $ports['sfp-sfpplus2']->tagged_vlans]);
        $this->assertSame([10, 20], $ports['qsfpplus1-1']->tagged_vlans);
    }

    public function test_routeros_vlans_are_read_through_the_rest_api(): void
    {
        $this->agent->asMikrotik()->withoutVlanTables();
        Http::preventStrayRequests();
        $unauthorized = false;
        $responses = [
            'https://192.0.2.2:8443/rest/interface/bridge/vlan' => [
                ['bridge' => 'bridge', 'vlan-ids' => '10', 'tagged' => 'bridge,sfp-sfpplus1', 'untagged' => '', 'current-tagged' => 'bridge,sfp-sfpplus1,qsfpplus1-1', 'current-untagged' => 'ether1', 'disabled' => 'false'],
                ['bridge' => 'bridge', 'vlan-ids' => '20', 'tagged' => 'bridge,sfp-sfpplus1,qsfpplus1-1', 'untagged' => 'sfp-sfpplus2', 'disabled' => 'false'],
                ['bridge' => 'bridge', 'vlan-ids' => '30-31', 'tagged' => 'bridge,sfp-sfpplus1', 'untagged' => '', 'disabled' => 'false'],
                ['bridge' => 'bridge', 'vlan-ids' => '99', 'tagged' => 'ether1', 'disabled' => 'true'],
            ],
            'https://192.0.2.2:8443/rest/interface/vlan' => [['name' => 'mgmt', 'vlan-id' => '40', 'interface' => 'bridge', 'disabled' => 'false']],
            'https://192.0.2.2:8443/rest/interface/bridge/port' => [
                ['interface' => 'ether1', 'pvid' => '10', 'bridge' => 'bridge'], ['interface' => 'sfp-sfpplus2', 'pvid' => '20', 'bridge' => 'bridge'],
                ['interface' => 'sfp-sfpplus1', 'pvid' => '1', 'bridge' => 'bridge'], ['interface' => 'qsfpplus1-1', 'pvid' => '1', 'bridge' => 'bridge'],
            ],
        ];
        Http::fake(function (Request $request) use (&$unauthorized, $responses) {
            return $unauthorized ? Http::response(status: 401) : Http::response($responses[$request->url()] ?? null, isset($responses[$request->url()]) ? 200 : 404);
        });
        $device = NetworkDevice::factory()->create([
            'host' => '192.0.2.2', 'api_driver' => 'routeros', 'api_port' => 8443, 'api_verify_tls' => false,
            'api_credentials' => ['username' => 'nexora', 'password' => 'api-secret'],
        ]);
        $run = ScanRun::queue('snmp', $device);
        $token = (string) Str::uuid();
        $device->forceFill(['poll_token' => $token, 'poll_status' => 'queued'])->save();
        app()->call([new PollNetworkDevice($device->id, $token, $run->id), 'handle']);

        $ports = $device->ports()->get()->keyBy('name');
        $this->assertSame([10, [10], null], [$ports['ether1']->pvid, $ports['ether1']->untagged_vlans, $ports['ether1']->tagged_vlans]);
        $this->assertSame([1, [10, 20, 30, 31]], [$ports['sfp-sfpplus1']->pvid, $ports['sfp-sfpplus1']->tagged_vlans]);
        $this->assertSame([20, [20]], [$ports['sfp-sfpplus2']->pvid, $ports['sfp-sfpplus2']->untagged_vlans]);
        $this->assertSame([10, 20, 30, 31, 40], $ports['bridge']->tagged_vlans);
        $this->assertNull($device->fresh()->api_error);
        $this->assertSame(5, $run->fresh()->summary['api_ports']);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Basic '.base64_encode('nexora:api-secret')));

        $unauthorized = true;
        $token = (string) Str::uuid();
        $device->refresh()->forceFill(['poll_token' => $token, 'poll_status' => 'queued'])->save();
        app()->call([new PollNetworkDevice($device->id, $token), 'handle']);

        $device->refresh();
        $this->assertSame(['completed', 'L’API RouterOS a refusé les identifiants.'], [$device->poll_status, $device->api_error]);
        $this->assertSame([1, null], [$device->ports()->where('name', 'ether1')->value('pvid'), $device->ports()->where('name', 'ether1')->value('untagged_vlans')]);
        $this->assertSame([null, 'L’API RouterOS a refusé les identifiants.'], AuditLog::where('action', 'device.polled')->latest('id')->first()->changes['api_error']);

        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(DeviceDetail::class, ['device' => $device])->assertSee('VLAN lus en SNMP : L’API RouterOS a refusé les identifiants.');
    }

    public function test_administrators_configure_the_device_api_with_an_encrypted_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $device = NetworkDevice::factory()->create();

        $component = Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->set('form.api_driver', 'routeros')->call('save')->assertHasErrors(['form.api_username', 'form.api_password'])
            ->set('form.api_driver', 'unknown')->call('save')->assertHasErrors('form.api_driver')
            ->set('form.api_driver', 'routeros')->set('form.api_username', 'nexora')->set('form.api_password', 'api-secret')
            ->set('form.api_verify_tls', false)->call('save')->assertHasNoErrors();

        $device->refresh();
        $this->assertSame(['routeros', 443, false], [$device->api_driver, $device->api_port, $device->api_verify_tls]);
        $this->assertSame(['username' => 'nexora', 'password' => 'api-secret'], $device->api_credentials);
        $this->assertStringNotContainsString('api-secret', (string) $device->getRawOriginal('api_credentials'));
        $this->assertSame([null, Audit::MASK], AuditLog::where('action', 'updated')->where('subject_type', 'network_device')->latest('id')->first()->changes['api_credentials']);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->assertSet('form.api_password', '')->assertSet('form.api_username', 'nexora')->call('save')->assertHasNoErrors();
        $this->assertSame('api-secret', $device->fresh()->api_credentials['password']);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->set('form.api_driver', '')->call('save')->assertHasNoErrors();
        $this->assertSame([null, null, null], [$device->fresh()->api_driver, $device->fresh()->api_port, $device->fresh()->api_credentials]);
    }

    public function test_the_snmp_dump_prints_the_tables_without_credentials(): void
    {
        $this->agent->asMikrotik();
        NetworkDevice::factory()->create(['name' => 'crs-core', 'credentials' => ['community' => 'top-secret']]);

        $this->artisan('nexora:snmp-dump', ['device' => 'crs-core'])->assertSuccessful()
            ->expectsOutputToContain('## vlan-current')
            ->expectsOutputToContain('1.3.6.1.2.1.31.1.1.1.1.2 = sfp-sfpplus1')
            ->doesntExpectOutputToContain('top-secret');
        $this->artisan('nexora:snmp-dump', ['device' => 'absent'])->assertFailed();
    }

    public function test_a_second_poll_computes_the_throughput_of_each_port(): void
    {
        $device = $this->poll(NetworkDevice::factory()->create());
        $this->assertNull($device->ports()->where('name', 'GigabitEthernet1/0/1')->value('in_bps'));

        $device->forceFill(['polled_at' => now()->subSeconds(60)])->save();
        $this->agent = new FakeSnmpAgent(trafficStep: 1);
        $port = $this->poll($device)->ports()->where('name', 'GigabitEthernet1/0/1')->sole();

        $this->assertSame(50_000_000, $port->in_bps);
        $this->assertSame(10_000_000, $port->out_bps);
        $this->assertSame(5.0, $port->utilization());
        $this->assertSame('50,0 Mb/s', DevicePort::rate($port->in_bps));
    }

    public function test_unreachable_devices_fail_and_raise_an_alert(): void
    {
        $this->agent->online = false;
        $device = $this->poll(NetworkDevice::factory()->create(['name' => 'sw-cave']));

        $this->assertSame('failed', $device->poll_status);
        $this->assertStringContainsString('ne répond pas en SNMP', $device->poll_error);
        $this->assertSame('critical', Alert::where('type', 'device_unreachable')->sole()->severity);

        $this->agent->online = true;
        $this->poll($device);
        $this->assertNotNull(Alert::where('type', 'device_unreachable')->sole()->resolved_at);
    }

    public function test_the_faceplate_shows_ports_and_the_selected_port_details(): void
    {
        $this->withoutVite();
        Subnet::factory()->create(['cidr' => '192.0.2.0/24']);
        $device = $this->poll(NetworkDevice::factory()->create(['name' => 'sw-rdc']));
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->get(route('devices'))->assertOk()->assertSee('sw-rdc')->assertSee('18 / 28');
        $this->get(route('devices.show', $device))->assertOk()->assertSee('GigabitEthernet1/0/24')->assertSee('core-sw01.example.test');

        $server = $device->ports()->where('name', 'GigabitEthernet1/0/13')->value('if_index');
        Livewire::test(DeviceDetail::class, ['device' => $device])
            ->call('select', $server)->assertSee('00:15:5d:00:13:01')->assertSee('192.0.2.50')->assertSee('srv-hyperv-01')
            ->set('search', '00-15-5D-00-13-02')->assertSeeHtml('wire:key="row-1013"')->assertDontSeeHtml('wire:key="row-1014"')
            ->call('poll')->assertForbidden();

        Livewire::test(Inventory::class, ['section' => 'addresses'])
            ->call('showHistory', IpAddress::where('address', '192.0.2.50')->value('id'))
            ->assertSee('sw-rdc · GigabitEthernet1/0/13')->assertSee('VLAN 20');
    }

    public function test_due_devices_are_polled_by_the_scheduler(): void
    {
        $due = NetworkDevice::factory()->create(['poll_interval' => 15, 'next_poll_at' => now()->subMinute()]);
        NetworkDevice::factory()->create(['poll_interval' => 15, 'next_poll_at' => now()->addMinute()]);
        NetworkDevice::factory()->create(['poll_interval' => null]);

        $this->artisan('nexora:poll-devices')->assertSuccessful();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('queued', $due->fresh()->poll_status);
        $this->assertTrue($due->fresh()->next_poll_at->isFuture());
    }

    public function test_output_parsing_handles_quotes_wrapped_hex_and_missing_objects(): void
    {
        $parsed = SnmpClient::parse(".1.3.6.1.2.1.1.5.0 \"core \\\"A\\\"\"\n.1.3.6.1.2.1.17.7.1.4.3.1.2.10 \"FF 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00\n80 \"\n.1.3.6.1.2.1.1.9 No Such Object available on this agent at this OID\n");

        $this->assertSame('core "A"', $parsed['1.3.6.1.2.1.1.5.0']);
        $this->assertSame([...range(1, 8), 129], SnmpClient::portList($parsed['1.3.6.1.2.1.17.7.1.4.3.1.2.10']));
        $this->assertArrayNotHasKey('1.3.6.1.2.1.1.9', $parsed);
    }

    private function poll(NetworkDevice $device): NetworkDevice
    {
        $token = (string) Str::uuid();
        $device->refresh()->forceFill(['poll_token' => $token, 'poll_status' => 'queued'])->save();
        app()->call([new PollNetworkDevice($device->id, $token), 'handle']);

        return $device->refresh();
    }
}
