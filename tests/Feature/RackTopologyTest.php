<?php

namespace Tests\Feature;

use App\Livewire\NetworkTopology;
use App\Livewire\Racks;
use App\Models\AuditLog;
use App\Models\CableLink;
use App\Models\DevicePort;
use App\Models\DevicePortMac;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Rack;
use App\Models\RackItem;
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

        $this->get(route('racks'))->assertOk()->assertSeeHtml('<path d="M0 204 C14 204, 14 60, 0 60" data-source="lldp" :class="{ \'is-hot\': hot === \'l0\' }"><title>48 ↔ 1</title></path>');
        $this->get(route('topology'))->assertOk()->assertSee('1 liaison détectée')->assertSee('sw-access')->assertSee('ether48');
        Livewire::test(NetworkTopology::class)->assertViewHas('links', fn ($links) => $links->count() === 1);
    }

    public function test_operators_document_cables_between_rack_items(): void
    {
        $this->withoutVite();
        $switch = NetworkDevice::factory()->create(['name' => 'sw-a']);
        $this->port($switch, 1, 'ether1');
        $rack = Rack::factory()->create(['name' => 'Baie A', 'units' => 10]);
        $device = $rack->items()->create(['network_device_id' => $switch->id, 'kind' => 'device', 'position' => 9, 'height' => 1]);
        $panel = Rack::factory()->create(['name' => 'Baie B', 'units' => 10])->items()->create(['kind' => 'patch_panel', 'label' => 'Tiroir F', 'position' => 9, 'height' => 1]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(Racks::class)->call('open', 'cable', null, $device->id)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        Livewire::test(Racks::class)
            ->call('open', 'cable', null, $device->id)->assertSet('form.a_rack_item_id', $device->id)
            ->assertSeeHtml('<div class="select-layer" data-select-layer wire:ignore></div>')
            ->call('save')->assertHasErrors('form.b_rack_item_id')
            ->set('form.b_rack_item_id', $device->id)->call('save')->assertHasErrors('form.b_rack_item_id')
            ->set('form.b_rack_item_id', $panel->id)->set('form.a_port', 'ether99')->call('save')->assertHasErrors('form.a_port')
            ->set('form.a_port', 'ether1')->set('form.b_port', ' 12 ')->set('form.medium', 'laser')->call('save')->assertHasErrors('form.medium')
            ->set('form.medium', 'fiber')->set('form.label', 'F12')->call('save')->assertHasNoErrors();

        $cable = CableLink::sole();
        $this->assertSame([$device->id, 'ether1', $panel->id, '12', 'fiber', 'F12'], [$cable->a_rack_item_id, $cable->a_port, $cable->b_rack_item_id, $cable->b_port, $cable->medium, $cable->label]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'subject_type' => 'cable_link', 'subject_label' => 'F12 · sw-a ether1 ↔ Tiroir F 12']);

        Livewire::test(Racks::class)->call('open', 'cable', $cable->id)->set('form.b_rack_item_id', $device->id)->assertSet('form.b_port', '');
        $this->get(route('racks'))->assertSee('Tiroir F')->assertSee('MANUEL')->assertSee('Fibre');

        Livewire::test(Racks::class)->call('confirmDelete', 'item', $panel->id)->call('delete');
        $this->assertModelMissing($cable);
        $this->assertSame(1, AuditLog::where('action', 'deleted')->where('subject_type', 'cable_link')->count());
    }

    public function test_documented_cables_confirm_detected_links_or_add_manual_ones(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        [$access, $core, $edge] = [
            NetworkDevice::factory()->create(['name' => 'sw-access', 'sys_name' => 'sw-access']),
            NetworkDevice::factory()->create(['name' => 'core', 'sys_name' => 'core']),
            NetworkDevice::factory()->create(['name' => 'edge']),
        ];
        $this->port($access, 48, 'ether48', ['neighbor_name' => 'core', 'neighbor_port' => 'ether1']);
        $this->port($core, 1, 'ether1');
        $this->port($core, 2, 'ether2');
        $this->port($edge, 5, 'ether5');
        $rack = Rack::factory()->create(['units' => 10]);
        [$a, $c, $e] = collect([$access, $core, $edge])->map(fn (NetworkDevice $device, int $index): RackItem => $rack->items()->create(['network_device_id' => $device->id, 'kind' => 'device', 'position' => $index * 2 + 1, 'height' => 1]))->all();
        CableLink::create(['a_rack_item_id' => $c->id, 'a_port' => 'ether1', 'b_rack_item_id' => $a->id, 'b_port' => 'ether48', 'medium' => 'copper']);
        CableLink::create(['a_rack_item_id' => $c->id, 'a_port' => 'ether2', 'b_rack_item_id' => $e->id, 'medium' => 'dac']);

        $links = app(Topology::class)->links();

        $this->assertCount(2, $links);
        $this->assertSame([['lldp', true], ['manual', false]], $links->map(fn (array $link): array => [$link['source'], $link['confirmed']])->all());
        $this->assertSame(['core', 'ether2', 'edge', null], [$links[1]['a']->name, $links[1]['a_port']->name, $links[1]['b']->name, $links[1]['b_port']]);
        $this->get(route('topology'))->assertOk()->assertSee('Manuelle')->assertSee('edge');
    }

    public function test_racks_of_a_location_form_a_row_with_the_cables_between_them(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $paris = Location::factory()->create(['name' => 'Paris']);
        $lyon = Location::factory()->create(['name' => 'Lyon']);
        $access = NetworkDevice::factory()->create(['name' => 'sw-access', 'sys_name' => 'sw-access']);
        $core = NetworkDevice::factory()->create(['name' => 'core', 'sys_name' => 'core']);
        $remote = NetworkDevice::factory()->create(['name' => 'sw-lyon', 'sys_name' => 'sw-lyon']);
        $this->port($access, 48, 'ether48', ['neighbor_name' => 'core', 'neighbor_port' => 'ether1']);
        $this->port($core, 1, 'ether1');
        $this->port($core, 2, 'ether2', ['neighbor_name' => 'sw-lyon', 'neighbor_port' => 'ether24']);
        $this->port($remote, 24, 'ether24');
        $second = Rack::factory()->create(['name' => 'Baie P2', 'location_id' => $paris->id, 'sort_order' => 2]);
        $first = Rack::factory()->create(['name' => 'Baie P1', 'location_id' => $paris->id, 'sort_order' => 1]);
        $lyonRack = Rack::factory()->create(['name' => 'Baie L1', 'location_id' => $lyon->id]);
        $coreItem = $first->items()->create(['network_device_id' => $core->id, 'kind' => 'device', 'position' => 10, 'height' => 1]);
        $accessItem = $second->items()->create(['network_device_id' => $access->id, 'kind' => 'device', 'position' => 10, 'height' => 1]);
        $lyonRack->items()->create(['network_device_id' => $remote->id, 'kind' => 'device', 'position' => 10, 'height' => 1]);

        $component = Livewire::test(Racks::class);
        $rows = $component->viewData('rows');
        $this->assertSame(['Lyon', 'Paris'], $rows->map(fn (array $row): string => $row['location']->name)->all());
        $this->assertSame(['Baie P1', 'Baie P2'], $rows[1]['racks']->pluck('name')->all());
        $this->assertSame([[$accessItem->id, $coreItem->id]], array_map(fn (array $cable): array => [$cable['a'], $cable['b']], $rows[1]['cables']));
        $this->assertSame([], $rows[0]['cables']);

        $component->assertSeeHtml('data-item="'.$coreItem->id.'"')->assertSee('1 liaison entre baies')
            ->assertSee('Baie P2')->assertSee('Baie L1')->assertSeeHtml('@click="jump('.$accessItem->id.', ');
    }

    public function test_rooms_with_several_racks_get_a_rack_navigation_and_a_compact_view(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $paris = Location::factory()->create(['name' => 'Paris']);
        $lyon = Location::factory()->create(['name' => 'Lyon']);
        $first = Rack::factory()->create(['name' => 'Baie P1', 'location_id' => $paris->id, 'sort_order' => 1]);
        $second = Rack::factory()->create(['name' => 'Baie P2', 'location_id' => $paris->id, 'sort_order' => 2]);
        $alone = Rack::factory()->create(['name' => 'Baie L1', 'location_id' => $lyon->id]);

        Livewire::test(Racks::class)
            ->assertSeeHtml('x-data="rackRooms"')
            ->assertSee('Vue compacte')
            ->assertSeeHtml('aria-label="Baies de Paris"')
            ->assertSeeHtml('@click="go('.$first->id.')"')
            ->assertSeeHtml('@click="go('.$second->id.')"')
            ->assertDontSeeHtml('@click="go('.$alone->id.')"')
            ->assertDontSeeHtml('aria-label="Baies de Lyon"');
    }

    public function test_the_medium_of_a_detected_link_is_set_from_the_topology_or_the_racks(): void
    {
        $this->withoutVite();
        $access = NetworkDevice::factory()->create(['name' => 'sw-access', 'sys_name' => 'sw-access']);
        $core = NetworkDevice::factory()->create(['name' => 'core', 'sys_name' => 'core']);
        $this->port($access, 48, 'ether48', ['neighbor_name' => 'core', 'neighbor_port' => 'ether1']);
        $this->port($core, 1, 'ether1');

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(NetworkTopology::class)->assertDontSeeHtml('wire:click="editMedium(')->call('editMedium', $access->id, 'ether48', $core->id, 'ether1')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        Livewire::test(NetworkTopology::class)
            ->call('editMedium', $access->id, 'ether48', $core->id, 'ether1')
            ->set('form.medium', 'laser')->call('save')->assertHasErrors('form.medium')
            ->set('form.medium', 'fiber')->set('form.label', 'F7')->call('save')->assertHasNoErrors()
            ->assertSee('Fibre')->assertSee('F7');

        $cable = CableLink::sole();
        $this->assertSame([null, $access->id, 'ether48', null, $core->id, 'ether1'], [$cable->a_rack_item_id, $cable->a_network_device_id, $cable->a_port, $cable->b_rack_item_id, $cable->b_network_device_id, $cable->b_port]);
        $link = app(Topology::class)->links()->sole();
        $this->assertSame(['lldp', true, 'fiber'], [$link['source'], $link['confirmed'], $link['cable']->medium]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'subject_type' => 'cable_link', 'subject_label' => 'F7 · sw-access ether48 ↔ core ether1']);

        Livewire::test(NetworkTopology::class)->call('editMedium', $access->id, 'ether48', $core->id, 'ether1', $cable->id)
            ->assertSet('form.medium', 'fiber')->set('form.medium', 'dac')->call('save');
        $this->assertSame('dac', $cable->fresh()->medium);
        Livewire::test(NetworkTopology::class)->call('editMedium', $access->id, 'ether48', $core->id, 'ether1', $cable->id)->call('forget');
        $this->assertModelMissing($cable);
        $this->assertSame('lldp', app(Topology::class)->links()->sole()['source']);

        $rack = Rack::factory()->create(['units' => 10]);
        $a = $rack->items()->create(['network_device_id' => $access->id, 'kind' => 'device', 'position' => 2, 'height' => 1]);
        $c = $rack->items()->create(['network_device_id' => $core->id, 'kind' => 'device', 'position' => 8, 'height' => 1]);
        Livewire::test(Racks::class)->assertSeeHtml('wire:click="describe(')
            ->call('describe', $a->id, 'ether48', $c->id, 'ether1')
            ->assertSet('form.a_rack_item_id', $a->id)->assertSet('form.b_port', 'ether1')
            ->set('form.medium', 'copper')->call('save')->assertHasNoErrors();
        $this->assertSame([$a->id, $c->id, 'copper'], [CableLink::sole()->a_rack_item_id, CableLink::sole()->b_rack_item_id, CableLink::sole()->medium]);
        $this->assertTrue(app(Topology::class)->links()->sole()['confirmed']);

        Livewire::test(NetworkTopology::class)->call('editMedium', $core->id, 'ether1', $access->id, 'ether48')->call('save');
        $this->assertSame(2, CableLink::count(), 'A second cable for the same link is listed as manual, not merged.');
    }

    public function test_the_topology_is_laid_out_in_layers_from_the_internet_down(): void
    {
        $this->withoutVite();
        $firewall = NetworkDevice::factory()->create(['name' => 'fw', 'sys_name' => 'fw']);
        $core = NetworkDevice::factory()->create(['name' => 'core', 'sys_name' => 'core']);
        $access = NetworkDevice::factory()->create(['name' => 'access', 'sys_name' => 'access']);
        $printer = NetworkDevice::factory()->create(['name' => 'lonely']);
        $this->port($firewall, 1, 'out', ['wan' => true, 'wan_label' => 'Fibre opérateur']);
        $this->port($firewall, 2, 'in', ['neighbor_name' => 'core', 'neighbor_port' => 'ether1']);
        $this->port($core, 1, 'ether1');
        $this->port($core, 2, 'ether2', ['neighbor_name' => 'access', 'neighbor_port' => 'ether24']);
        $this->port($access, 24, 'ether24');
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $view = Livewire::test(NetworkTopology::class);
        $nodes = $view->viewData('nodes');
        $this->assertNotNull($view->viewData('internet'));
        $this->assertSame([1, 2, 3, 0], [$nodes[$firewall->id]['level'], $nodes[$core->id]['level'], $nodes[$access->id]['level'], $nodes[$printer->id]['level']]);
        $this->assertTrue($nodes[$firewall->id]['y'] < $nodes[$core->id]['y'] && $nodes[$core->id]['y'] < $nodes[$access->id]['y'] && $nodes[$access->id]['y'] < $nodes[$printer->id]['y']);
        $this->assertSame(['out'], array_map(fn (array $wan): string => $wan['port']->name, $view->viewData('wans')));
        $view->assertSee('Internet')->assertSee('Fibre opérateur')->assertSee('Sans liaison connue');

        $access->update(['role' => 'core']);
        $this->assertSame(2, Livewire::test(NetworkTopology::class)->viewData('nodes')[$access->id]['level'], 'A role sets the layer.');

        DevicePort::query()->update(['wan' => false]);
        $without = Livewire::test(NetworkTopology::class);
        $this->assertNull($without->viewData('internet'));
        $without->assertSee('Marquez le port de sortie');
        $this->assertSame(1, $without->viewData('nodes')[$core->id]['level'], 'Without an edge, the best connected device leads.');
    }

    public function test_branches_are_drawn_as_trees_and_many_leaves_wrap_under_their_parent(): void
    {
        $this->withoutVite();
        $paris = Location::factory()->create(['name' => 'Paris']);
        $lyon = Location::factory()->create(['name' => 'Lyon']);
        $core = NetworkDevice::factory()->create(['name' => 'core', 'sys_name' => 'core', 'role' => 'core', 'location_id' => $paris->id]);
        $distributions = [];
        foreach (['dist-a', 'dist-b'] as $index => $name) {
            $distributions[$name] = NetworkDevice::factory()->create(['name' => $name, 'sys_name' => $name, 'role' => 'distribution', 'location_id' => $paris->id]);
            $this->port($distributions[$name], 1, 'up', ['neighbor_name' => 'core', 'neighbor_port' => 'ether'.($index + 1)]);
        }
        $access = [];
        foreach (range(1, 10) as $number) {
            $parent = $number <= 8 ? 'dist-a' : 'dist-b';
            $access[$number] = NetworkDevice::factory()->create(['name' => sprintf('acc-%02d', $number), 'role' => 'access', 'location_id' => $number === 10 ? $lyon->id : $paris->id]);
            $this->port($access[$number], 1, 'ge1', ['neighbor_name' => $parent, 'neighbor_port' => 'p'.$number]);
        }
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $view = Livewire::test(NetworkTopology::class);
        $nodes = $view->viewData('nodes');
        $underA = collect(range(1, 8))->map(fn (int $number): array => $nodes[$access[$number]->id]);
        $this->assertSame(NetworkTopology::WRAP, $underA->where('y', $underA->min('y'))->count(), 'Leaves wrap after WRAP columns.');
        $this->assertSame(2, $underA->pluck('y')->unique()->count());
        $this->assertTrue($nodes[$distributions['dist-a']->id]['x'] > $underA->min('x') && $nodes[$distributions['dist-a']->id]['x'] < $underA->max('x'), 'A parent sits above its leaves.');
        $this->assertGreaterThan($underA->max('x'), $nodes[$access[9]->id]['x'], 'Each branch keeps its own columns.');
        $this->assertSame(4, $nodes[$access[1]->id]['level']);
        $this->assertLessThan(10 * 210, $view->viewData('width'), 'Wrapping keeps the drawing narrower than one row per layer.');
        $view->assertSeeHtml('x-data="topologyView(')->assertSeeHtml('@click="select($event, '.$core->id.')"')->assertSee('Tout afficher');

        $filtered = Livewire::test(NetworkTopology::class)->set('location', (string) $paris->id);
        $this->assertFalse($filtered->viewData('nodes')->has($access[10]->id));
        $this->assertTrue($filtered->viewData('nodes')->has($access[9]->id));
        $this->assertSame(11, $filtered->viewData('links')->count(), 'Links to devices of other locations are left out.');
        $this->assertSame(12, $view->viewData('links')->count());
    }

    public function test_a_switch_chained_behind_a_peer_of_its_layer_hangs_below_it(): void
    {
        $this->withoutVite();
        $core = NetworkDevice::factory()->create(['name' => 'core-1', 'sys_name' => 'core-1', 'role' => 'core']);
        $backup = NetworkDevice::factory()->create(['name' => 'core-2', 'sys_name' => 'core-2', 'role' => 'core']);
        $first = NetworkDevice::factory()->create(['name' => 'acc-1', 'sys_name' => 'acc-1', 'role' => 'access']);
        $second = NetworkDevice::factory()->create(['name' => 'acc-2', 'sys_name' => 'acc-2', 'role' => 'access']);
        $third = NetworkDevice::factory()->create(['name' => 'acc-3', 'sys_name' => 'acc-3', 'role' => 'access']);
        $ring = NetworkDevice::factory()->create(['name' => 'acc-ring', 'sys_name' => 'acc-ring', 'role' => 'access']);
        $this->port($backup, 1, 'ether1', ['neighbor_name' => 'core-1', 'neighbor_port' => 'ether9']);
        $this->port($first, 1, 'up', ['neighbor_name' => 'core-1', 'neighbor_port' => 'ether1']);
        $this->port($second, 1, 'up', ['neighbor_name' => 'acc-1', 'neighbor_port' => 'ge24']);
        $this->port($third, 1, 'up', ['neighbor_name' => 'acc-2', 'neighbor_port' => 'ge24']);
        $this->port($ring, 1, 'up', ['neighbor_name' => 'core-1', 'neighbor_port' => 'ether2']);
        $this->port($ring, 2, 'ge2', ['neighbor_name' => 'acc-1', 'neighbor_port' => 'ge23']);
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $nodes = Livewire::test(NetworkTopology::class)->viewData('nodes');
        $this->assertSame([2, 2], [$nodes[$core->id]['level'], $nodes[$backup->id]['level']], 'Two linked cores stay peers.');
        $this->assertSame($nodes[$core->id]['y'], $nodes[$backup->id]['y']);
        $this->assertSame([4, 5, 6], [$nodes[$first->id]['level'], $nodes[$second->id]['level'], $nodes[$third->id]['level']]);
        $this->assertTrue($nodes[$first->id]['y'] < $nodes[$second->id]['y'] && $nodes[$second->id]['y'] < $nodes[$third->id]['y'], 'A chain of access switches goes down.');
        $this->assertSame($nodes[$first->id]['x'], $nodes[$second->id]['x'], 'A chained switch sits under the one it hangs from.');
        $this->assertSame($nodes[$first->id]['y'], $nodes[$ring->id]['y'], 'A switch with its own uplink stays in its layer.');
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
