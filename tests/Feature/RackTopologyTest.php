<?php

namespace Tests\Feature;

use App\Livewire\NetworkTopology;
use App\Livewire\Racks;
use App\Models\DevicePort;
use App\Models\DevicePortMac;
use App\Models\NetworkDevice;
use App\Models\Rack;
use App\Models\User;
use App\Support\Topology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RackTopologyTest extends TestCase
{
    use RefreshDatabase;

    public function test_lldp_neighbours_link_devices_by_system_name_and_port(): void
    {
        $access = NetworkDevice::factory()->create(['name' => 'sw-access', 'sys_name' => 'sw-access']);
        $core = NetworkDevice::factory()->create(['name' => 'Cœur', 'sys_name' => 'core-sw01']);
        $uplink = $this->port($access, 48, 'TenGigabitEthernet1/1/1', ['neighbor_name' => 'core-sw01.example.test', 'neighbor_port' => 'Te1/0/1']);
        $downlink = $this->port($core, 1, 'TenGigabitEthernet1/0/1', ['neighbor_name' => 'sw-access', 'neighbor_port' => 'TenGigabitEthernet1/1/1']);

        $links = app(Topology::class)->links();

        $this->assertCount(1, $links);
        $this->assertSame('lldp', $links[0]['source']);
        $this->assertSame([$uplink->id, $downlink->id], [$links[0]['a_port']->id, $links[0]['b_port']->id]);
    }

    public function test_without_lldp_switches_are_linked_to_the_closest_peer_that_sees_them_back(): void
    {
        [$c, $d, $e] = [NetworkDevice::factory()->create(['name' => 'c']), NetworkDevice::factory()->create(['name' => 'd']), NetworkDevice::factory()->create(['name' => 'e'])];
        $c5 = $this->port($c, 5, 'ether5', ['mac_address' => '00:00:00:00:0c:05', 'mac_count' => 30]);
        $d7 = $this->port($d, 7, 'ether7', ['mac_address' => '00:00:00:00:0d:07', 'mac_count' => 2]);
        $d9 = $this->port($d, 9, 'ether9', ['mac_address' => '00:00:00:00:0d:09', 'mac_count' => 3]);
        $e1 = $this->port($e, 1, 'ether1', ['mac_address' => '00:00:00:00:0e:01', 'mac_count' => 40]);

        // C sees D and E behind its port 5; D sees C on 7 and E on 9; E sees C and D on its uplink.
        $this->learn($c5, ['00:00:00:00:0d:07', '00:00:00:00:0e:01']);
        $this->learn($d7, ['00:00:00:00:0c:05']);
        $this->learn($d9, ['00:00:00:00:0e:01']);
        $this->learn($e1, ['00:00:00:00:0c:05', '00:00:00:00:0d:09']);

        $pairs = app(Topology::class)->links()
            ->map(fn (array $link): string => collect([$link['a']->name.':'.$link['a_port']->name, $link['b']->name.':'.$link['b_port']?->name])->sort()->implode(' - '))
            ->sort()->values()->all();

        $this->assertSame(['c:ether5 - d:ether7', 'd:ether9 - e:ether1'], $pairs);
    }

    public function test_operators_place_devices_and_passive_items_in_racks(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(Racks::class)->call('open', 'rack')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $switch = NetworkDevice::factory()->create(['name' => 'sw-baie-a']);
        Livewire::test(Racks::class)->call('open', 'rack')->set('form.name', 'Baie A')->set('form.units', 12)->call('save')->assertHasNoErrors();
        $rack = Rack::sole();

        Livewire::test(Racks::class)
            ->call('open', 'item', null, $rack->id, 10)->set('form.network_device_id', $switch->id)->set('form.height', 2)->call('save')->assertHasNoErrors()
            ->call('open', 'item', null, $rack->id, 11)->set('form.kind', 'patch_panel')->set('form.label', '')->call('save')->assertHasErrors('form.label')
            ->set('form.label', 'Brassage 1')->call('save')->assertHasErrors('form.position')
            ->set('form.position', 12)->set('form.height', 2)->call('save')->assertHasErrors('form.position')
            ->set('form.height', 1)->call('save')->assertHasNoErrors()
            ->call('open', 'item', null, $rack->id, 1)->set('form.network_device_id', $switch->id)->call('save')->assertHasErrors('form.network_device_id')
            ->call('open', 'rack', $rack->id)->set('form.units', 10)->call('save')->assertHasErrors('form.units');

        $this->assertSame([[10, 2], [12, 1]], $rack->items()->reorder('position')->get()->map(fn ($item) => [$item->position, $item->height])->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'subject_type' => 'rack_item', 'subject_label' => 'sw-baie-a · U10 · Baie A']);
        $this->get(route('racks'))->assertOk()->assertSee('Baie A')->assertSee('sw-baie-a')->assertSee('Brassage 1')->assertSee('3 U sur 12');
    }

    public function test_links_are_drawn_between_devices_of_a_rack_and_on_the_topology(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $access = NetworkDevice::factory()->create(['name' => 'sw-access', 'sys_name' => 'sw-access']);
        $core = NetworkDevice::factory()->create(['name' => 'core', 'sys_name' => 'core']);
        $this->port($access, 48, 'ether48', ['neighbor_name' => 'core', 'neighbor_port' => 'ether1']);
        $this->port($core, 1, 'ether1');
        $rack = Rack::factory()->create(['name' => 'Baie B', 'units' => 10]);
        $rack->items()->create(['network_device_id' => $access->id, 'kind' => 'device', 'position' => 2, 'height' => 1]);
        $rack->items()->create(['network_device_id' => $core->id, 'kind' => 'device', 'position' => 8, 'height' => 1]);

        $this->get(route('racks'))->assertOk()->assertSeeHtml('<path d="M0 204 C14 204, 14 60, 0 60" data-source="lldp"><title>48 ↔ 1</title></path>');
        $this->get(route('topology'))->assertOk()->assertSee('1 liaison détectée')->assertSee('sw-access')->assertSee('ether48');
        Livewire::test(NetworkTopology::class)->assertViewHas('links', fn ($links) => $links->count() === 1);
    }

    /** @param array<string, mixed> $attributes */
    private function port(NetworkDevice $device, int $index, string $name, array $attributes = []): DevicePort
    {
        return DevicePort::create(['network_device_id' => $device->id, 'if_index' => $index, 'name' => $name, 'physical' => true, 'oper_status' => 1] + $attributes);
    }

    /** @param list<string> $macs */
    private function learn(DevicePort $port, array $macs): void
    {
        foreach ($macs as $mac) {
            DevicePortMac::create(['network_device_id' => $port->network_device_id, 'device_port_id' => $port->id, 'mac_address' => $mac, 'seen_at' => now()]);
        }
    }
}
