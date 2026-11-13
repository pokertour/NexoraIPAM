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
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\ScanRun;
use App\Models\Subnet;
use App\Models\User;
use App\Support\Audit;
use App\Support\DeviceApi\RouterOsApi;
use App\Support\DeviceApi\RouterOsApiPlain;
use App\Support\SnmpClient;
use App\Support\Topology;
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

    public function test_devices_are_filtered_by_location_with_its_sub_locations(): void
    {
        $this->withoutVite();
        $site = Location::factory()->create(['name' => 'Siège']);
        $floor = Location::factory()->create(['name' => '2e étage', 'parent_id' => $site->id]);
        $agency = Location::factory()->create(['name' => 'Agence Nantes']);
        NetworkDevice::factory()->create(['name' => 'sw-siege', 'location_id' => $site->id]);
        NetworkDevice::factory()->create(['name' => 'sw-etage2', 'location_id' => $floor->id]);
        NetworkDevice::factory()->create(['name' => 'sw-nantes', 'location_id' => $agency->id]);
        NetworkDevice::factory()->create(['name' => 'sw-sans-lieu']);
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->get(route('devices', ['location' => $site->id]))->assertOk()->assertSee('Filtrer par emplacement')
            ->assertSee(['sw-siege', 'sw-etage2'])->assertDontSee(['sw-nantes', 'sw-sans-lieu']);
        Livewire::test(NetworkDevices::class)->assertSee(['sw-siege', 'sw-nantes', 'sw-sans-lieu'])
            ->set('location', (string) $agency->id)->assertSee('sw-nantes')->assertDontSee('sw-siege')
            ->set('location', (string) $site->id)->set('search', 'etage')->assertSee('sw-etage2')->assertDontSee('sw-siege')
            ->set('location', '')->set('search', '')->assertSee('sw-sans-lieu');
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

    public function test_routeros_vlans_are_read_through_the_binary_api(): void
    {
        $this->agent->asMikrotik()->withoutVlanTables();
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, self::sentence(['!done'])
            .self::sentence(['!re', '=.id=*1', '=vlan-ids=10', '=current-tagged=bridge,sfp-sfpplus1', '=current-untagged=ether1', '=disabled=false'])
            .self::sentence(['!re', '=.id=*2', '=vlan-ids=20-21', '=tagged=sfp-sfpplus1', '=untagged=sfp-sfpplus2', '=disabled=false'])
            .self::sentence(['!done'])
            .self::sentence(['!re', '=name=mgmt', '=vlan-id=40', '=interface=bridge', '=comment=a=b'])
            .self::sentence(['!done'])
            .self::sentence(['!re', '=interface=ether1', '=pvid=10'])
            .self::sentence(['!re', '=interface=sfp-sfpplus2', '=pvid=20'])
            .self::sentence(['!done']));
        $tls = null;
        $this->app->instance(RouterOsApi::class, new RouterOsApi(function (NetworkDevice $device, bool $encrypted) use ($client, &$tls) {
            $tls = $encrypted;

            return $client;
        }));
        $device = $this->poll(NetworkDevice::factory()->create([
            'api_driver' => 'routeros-api-ssl', 'api_port' => 8729,
            'api_credentials' => ['username' => 'nexora', 'password' => 'api-secret'],
        ]));

        $ports = $device->ports()->get()->keyBy('name');
        $this->assertTrue($tls);
        $this->assertNull($device->api_error);
        $this->assertSame([10, [10], null], [$ports['ether1']->pvid, $ports['ether1']->untagged_vlans, $ports['ether1']->tagged_vlans]);
        $this->assertSame([10, 20, 21], $ports['sfp-sfpplus1']->tagged_vlans);
        $this->assertSame([20, [20, 21]], [$ports['sfp-sfpplus2']->pvid, $ports['sfp-sfpplus2']->untagged_vlans]);
        $this->assertSame([10, 40], $ports['bridge']->tagged_vlans);

        $sent = stream_get_contents($server);
        $this->assertStringContainsString(self::sentence(['/login', '=name=nexora', '=password=api-secret']), $sent);
        foreach (['/interface/bridge/vlan/print', '/interface/vlan/print', '/interface/bridge/port/print'] as $command) {
            $this->assertStringContainsString(self::sentence([$command]), $sent);
        }
    }

    public function test_the_binary_api_reports_rejected_credentials_and_old_routeros(): void
    {
        $this->agent->asMikrotik()->withoutVlanTables();
        $replies = [
            self::sentence(['!trap', '=message=invalid user name or password (6)']).self::sentence(['!done']),
            self::sentence(['!done', '=ret=ebddd18303a54111e2dea05a92ab46b4']),
        ];
        $expected = ['L’API RouterOS a refusé les identifiants.', 'Cette version de RouterOS est trop ancienne pour l’API : la version 6.43 ou plus récente est requise.'];
        $device = NetworkDevice::factory()->create(['api_driver' => 'routeros-api', 'api_credentials' => ['username' => 'nexora', 'password' => 'wrong']]);

        foreach ($replies as $index => $reply) {
            [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($server, $reply);
            $this->app->instance(RouterOsApiPlain::class, new RouterOsApiPlain(function (NetworkDevice $device, bool $encrypted) use ($client) {
                $this->assertFalse($encrypted);

                return $client;
            }));
            $device = $this->poll($device);

            $this->assertSame(['completed', $expected[$index]], [$device->poll_status, $device->api_error]);
        }
    }

    public function test_api_lengths_are_encoded_on_one_to_five_bytes(): void
    {
        $this->assertSame("\x7F", RouterOsApi::encodeLength(0x7F));
        $this->assertSame("\x80\x80", RouterOsApi::encodeLength(0x80));
        $this->assertSame("\xBF\xFF", RouterOsApi::encodeLength(0x3FFF));
        $this->assertSame("\xC0\x40\x00", RouterOsApi::encodeLength(0x4000));
        $this->assertSame("\xE0\x20\x00\x00", RouterOsApi::encodeLength(0x200000));
        $this->assertSame("\xF0\x10\x00\x00\x00", RouterOsApi::encodeLength(0x10000000));
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
        $this->assertSame(['routeros', null, false], [$device->api_driver, $device->api_port, $device->api_verify_tls]);
        $this->assertSame(['username' => 'nexora', 'password' => 'api-secret'], $device->api_credentials);
        $this->assertStringNotContainsString('api-secret', (string) $device->getRawOriginal('api_credentials'));
        $this->assertSame([null, Audit::MASK], AuditLog::where('action', 'updated')->where('subject_type', 'network_device')->latest('id')->first()->changes['api_credentials']);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->assertSet('form.api_password', '')->assertSet('form.api_username', 'nexora')->call('save')->assertHasNoErrors();
        $this->assertSame('api-secret', $device->fresh()->api_credentials['password']);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->set('form.api_port', 8443)->set('form.api_driver', 'routeros-api-ssl')->assertSet('form.api_port', null)
            ->assertSee('Laissez vide pour conserver le secret enregistré.')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['routeros-api-ssl', null, 'api-secret'], [$device->fresh()->api_driver, $device->fresh()->api_port, $device->fresh()->api_credentials['password']]);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->set('form.api_port', 18729)->call('save')->assertHasNoErrors();
        $this->assertSame(18729, $device->fresh()->api_port);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->set('form.api_driver', '')->call('save')->assertHasNoErrors();
        $this->assertSame([null, null, null], [$device->fresh()->api_driver, $device->fresh()->api_port, $device->fresh()->api_credentials]);
    }

    public function test_tls_failures_explain_their_reason_and_blame_the_certificate_only_when_it_is_verified(): void
    {
        $this->agent->asMikrotik()->withoutVlanTables();
        $device = NetworkDevice::factory()->create(['api_driver' => 'routeros', 'api_verify_tls' => true, 'api_credentials' => ['username' => 'nexora', 'password' => 'api-secret']]);
        $cases = [
            [true, 'cURL error 60: SSL certificate problem: self-signed certificate (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)', 'Le certificat de l’API RouterOS n’est pas reconnu (certificate problem: self-signed certificate). Installez un certificat valide ou désactivez la vérification.'],
            [false, 'cURL error 35: OpenSSL/3.0.13: error:0A000152:SSL routines::unsafe legacy renegotiation disabled (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)', 'La connexion chiffrée à l’API RouterOS a échoué (unsafe legacy renegotiation disabled). Vérifiez le port et le service www-ssl.'],
            [false, 'cURL error 7: Failed to connect to 192.0.2.2 port 443 after 3 ms: Connection refused', 'L’API RouterOS est injoignable. Vérifiez que le service www-ssl est activé et le port autorisé.'],
        ];

        $error = '';
        Http::fake(function () use (&$error) {
            return Http::failedConnection($error);
        });
        foreach ($cases as [$verify, $error, $expected]) {
            $device->forceFill(['api_verify_tls' => $verify])->save();

            $this->assertSame($expected, $this->poll($device)->api_error);
        }
    }

    public function test_lldp_neighbours_land_on_the_local_port_they_name(): void
    {
        $this->agent->asMikrotik()->withRouterOsLldp();
        $device = $this->poll(NetworkDevice::factory()->create());
        $ports = $device->ports()->get()->keyBy('name');

        $this->assertSame(['B1-Switch-J', 'bridge/sfp-sfpplus1'], [$ports['sfp-sfpplus1']->neighbor_name, $ports['sfp-sfpplus1']->neighbor_port]);
        $this->assertSame('Switch-Core-02', $ports['sfp-sfpplus2']->neighbor_name);
        $this->assertNull($ports['ether1']->neighbor_name);

        $core = NetworkDevice::factory()->create(['name' => 'Switch-Core-02']);
        $uplink = DevicePort::create(['network_device_id' => $core->id, 'if_index' => 22, 'name' => 'sfp-sfpplus22', 'physical' => true]);
        $link = app(Topology::class)->links()->sole();
        $this->assertTrue($link['b_port']?->is($uplink) || $link['a_port']->is($uplink));
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
        // The database keeps whole seconds: a frozen whole second keeps the interval exact.
        $this->freezeSecond();
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

    public function test_a_device_is_unreachable_after_two_failed_polls_in_a_row(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->agent->online = false;
        $device = $this->poll(NetworkDevice::factory()->create(['name' => 'sw-cave', 'poll_interval' => 15]));

        $this->assertSame(['unstable', 1], [$device->poll_status, $device->poll_failures]);
        $this->assertStringStartsWith('Informations système : ', $device->poll_error);
        $this->assertStringContainsString('ne répond pas en SNMP', $device->poll_error);
        $this->assertSame(0, Alert::count(), 'One lost poll raises no alert.');
        $this->assertEquals(now()->addMinutes(PollNetworkDevice::RETRY_MINUTES), $device->next_poll_at, 'A failed poll is tried again soon.');
        $this->assertSame('unstable', AuditLog::where('action', 'device.polled')->latest('id')->first()->changes['result'][1]);

        $device = $this->poll($device);
        $this->assertSame(['failed', 2], [$device->poll_status, $device->poll_failures]);
        $alert = Alert::where('type', 'device_unreachable')->sole();
        $this->assertSame(['critical', 2], [$alert->severity, $alert->details['failures']]);

        $this->agent->online = true;
        $device = $this->poll($device);
        $this->assertSame(['completed', 0], [$device->poll_status, $device->poll_failures]);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_a_request_lost_once_is_retried_without_failing_the_poll(): void
    {
        $calls = 0;
        Process::fake(function ($process) use (&$calls) {
            $calls++;

            return $calls === 3
                ? Process::result(errorOutput: 'Timeout: No Response from 192.0.2.2:161.', exitCode: 1)
                : Process::result(output: $this->agent->respond($process->command));
        });

        $device = $this->poll(NetworkDevice::factory()->create());
        $this->assertSame(['completed', 0], [$device->poll_status, $device->poll_failures]);
        $this->assertGreaterThan(0, $device->ports()->count());
    }

    public function test_snmp_timeout_retries_and_packet_size_follow_the_device_settings(): void
    {
        $commands = [];
        Process::fake(function ($process) use (&$commands) {
            $commands[] = $process->command;

            return Process::result(output: $this->agent->respond($process->command));
        });

        $this->poll(NetworkDevice::factory()->create());
        $this->assertSame(['-t', '2', '-r', '1'], array_slice($commands[0], 5, 4));
        $this->assertSame('snmpbulkwalk', $commands[1][0]);
        $this->assertEmpty(preg_grep('/^-Cr/', $commands[1]), 'The default packet size is the one of net-snmp.');

        $commands = [];
        $this->poll(NetworkDevice::factory()->create(['snmp_timeout' => 5, 'snmp_retries' => 3, 'snmp_bulk' => 2]));
        $this->assertSame(['-t', '5', '-r', '3'], array_slice($commands[0], 5, 4));
        $this->assertContains('-Cr2', $commands[1]);

        $commands = [];
        $this->poll(NetworkDevice::factory()->create(['snmp_bulk' => 0]));
        $this->assertSame('snmpwalk', $commands[1][0]);
    }

    public function test_administrators_tune_snmp_for_a_device_behind_a_vpn(): void
    {
        $this->withoutVite();
        $device = NetworkDevice::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->assertSet('form.snmp_bulk', '10')->assertSee('Réglages avancés : VPN ou lien lent')
            ->set('form.snmp_timeout', '45')->call('save')->assertHasErrors('form.snmp_timeout')
            ->set('form.snmp_timeout', '4')->set('form.snmp_retries', '3')->set('form.snmp_bulk', '2')->call('save')->assertHasNoErrors();
        $this->assertSame([4, 3, 2], [$device->fresh()->snmp_timeout, $device->fresh()->snmp_retries, $device->fresh()->snmp_bulk]);
        $this->assertSame(['snmp_timeout' => [null, 4], 'snmp_retries' => [null, 3], 'snmp_bulk' => [null, 2]],
            array_intersect_key(AuditLog::where('action', 'updated')->where('subject_type', 'network_device')->latest('id')->first()->changes, array_flip(['snmp_timeout', 'snmp_retries', 'snmp_bulk'])));

        Livewire::actingAs($admin)->test(NetworkDevices::class)->call('open', $device->id)
            ->set('form.snmp_timeout', '')->set('form.snmp_retries', '')->set('form.snmp_bulk', '10')->call('save')->assertHasNoErrors();
        $this->assertSame([null, null, null], [$device->fresh()->snmp_timeout, $device->fresh()->snmp_retries, $device->fresh()->snmp_bulk]);
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

    public function test_administrators_override_a_wrongly_detected_connector(): void
    {
        $this->withoutVite();
        $device = $this->poll(NetworkDevice::factory()->create(['name' => 'sw-dlink']));
        $port = $device->ports()->where('name', 'GigabitEthernet1/0/13')->sole();
        $this->assertSame('rj45', $port->connector());

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        Livewire::test(DeviceDetail::class, ['device' => $device])->call('setMedia', $port->if_index, 'sfp')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(DeviceDetail::class, ['device' => $device])->call('setMedia', $port->if_index, 'usb')->assertStatus(422);
        Livewire::test(DeviceDetail::class, ['device' => $device])
            ->call('select', $port->if_index)->assertSee('Type de connecteur')
            ->call('setMedia', $port->if_index, 'sfp')->assertDispatched('nexora-toast', tone: 'success')
            ->assertSeeHtml('data-media="sfp"');

        $this->assertSame(['rj45', 'sfp', 'sfp'], [$port->fresh()->media, $port->fresh()->media_override, $port->fresh()->connector()]);
        $this->assertSame(['port' => [null, 'GigabitEthernet1/0/13'], 'media' => ['auto', 'sfp']], AuditLog::where('action', 'device.port_media')->sole()->changes);

        $this->poll($device);
        $this->assertSame('sfp', $port->fresh()->connector(), 'A poll keeps the manual choice.');

        Livewire::test(DeviceDetail::class, ['device' => $device])->call('setMedia', $port->if_index, '');
        $this->assertSame([null, 'rj45'], [$port->fresh()->media_override, $port->fresh()->connector()]);
        $this->assertSame(2, AuditLog::where('action', 'device.port_media')->count());
    }

    public function test_administrators_edit_a_device_from_its_page(): void
    {
        $this->withoutVite();
        $device = NetworkDevice::factory()->create(['name' => 'sw-rdc', 'credentials' => ['community' => 'r34d-only']]);

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        Livewire::test(DeviceDetail::class, ['device' => $device])->assertDontSee('Modifier l’équipement')->call('open', $device->id)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(DeviceDetail::class, ['device' => $device])
            ->call('open', $device->id)->assertSee('Modifier l’équipement')->assertSet('form.name', 'sw-rdc')
            ->set('form.name', 'sw-rdc-1')->call('save')->assertHasNoErrors()
            ->assertDispatched('nexora-toast', tone: 'success')->assertSet('editing', false)
            ->assertSee('sw-rdc-1');

        $this->assertSame(['sw-rdc-1', ['community' => 'r34d-only']], [$device->fresh()->name, $device->fresh()->credentials]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'subject_type' => 'network_device', 'subject_id' => $device->id]);
    }

    public function test_a_device_role_and_its_wan_ports_are_set_and_audited(): void
    {
        $this->withoutVite();
        $device = $this->poll(NetworkDevice::factory()->create(['name' => 'fw-sns']));
        $port = $device->ports()->where('name', 'GigabitEthernet1/0/1')->sole();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(NetworkDevices::class)->call('open', $device->id)->assertSet('form.role', 'other')
            ->set('form.role', 'cloud')->call('save')->assertHasErrors('form.role')
            ->set('form.role', 'edge')->call('save')->assertHasNoErrors();
        $this->assertSame('edge', $device->fresh()->role);
        $this->assertSame(['other', 'edge'], AuditLog::where('action', 'updated')->where('subject_type', 'network_device')->latest('id')->first()->changes['role']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(DeviceDetail::class, ['device' => $device])->assertSee('Routeur de bordure / pare-feu')->call('openWan', $port->if_index)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        Livewire::test(DeviceDetail::class, ['device' => $device])
            ->call('openWan', $port->if_index)->set('wan.enabled', true)->set('wan.label', 'Fibre opérateur')
            ->set('wan.address', 'not-an-ip')->call('saveWan')->assertHasErrors('wan.address')
            ->set('wan.address', '203.0.113.10')->call('saveWan')->assertHasNoErrors()->assertDispatched('nexora-toast', tone: 'success')
            ->call('select', $port->if_index)->assertSee('Fibre opérateur')->assertSeeHtml('data-wan');

        $this->assertSame([true, 'Fibre opérateur', '203.0.113.10'], [$port->fresh()->wan, $port->fresh()->wan_label, $port->fresh()->wan_address]);
        $this->assertSame(['port' => [null, 'GigabitEthernet1/0/1'], 'wan' => [false, true], 'wan_label' => [null, 'Fibre opérateur'], 'wan_address' => [null, '203.0.113.10']],
            AuditLog::where('action', 'device.port_wan')->sole()->changes);

        $this->poll($device);
        $this->assertTrue($port->fresh()->wan, 'A poll keeps the WAN marking.');

        Livewire::test(DeviceDetail::class, ['device' => $device])->call('openWan', $port->if_index)->set('wan.enabled', false)->call('saveWan');
        $this->assertSame([false, null, null], [$port->fresh()->wan, $port->fresh()->wan_label, $port->fresh()->wan_address]);
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

    /** @param  list<string>  $words */
    private static function sentence(array $words): string
    {
        return implode('', array_map(fn (string $word): string => RouterOsApi::encodeLength(strlen($word)).$word, [...$words, '']));
    }
}
