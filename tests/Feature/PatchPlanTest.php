<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\GlobalSearch;
use App\Livewire\PatchPlan;
use App\Models\AuditLog;
use App\Models\DevicePort;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\PortDestination;
use App\Models\User;
use App\Models\Vlan;
use App\Models\WallOutlet;
use App\Support\VlanColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PatchPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_switches_of_a_location_are_drawn_with_their_vlans_and_patched_outlets(): void
    {
        $this->withoutVite();
        $site = Location::factory()->create(['name' => 'Siège']);
        $floor = Location::factory()->create(['name' => '1er étage', 'parent_id' => $site->id]);
        $elsewhere = Location::factory()->create(['name' => 'Agence']);
        $switch = NetworkDevice::factory()->create(['name' => 'sw-etage1', 'location_id' => $floor->id]);
        $remote = NetworkDevice::factory()->create(['name' => 'sw-agence', 'location_id' => $elsewhere->id]);
        NetworkDevice::factory()->create(['name' => 'sans-ports', 'location_id' => $floor->id]);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 1, 'name' => 'eth1/0/1', 'physical' => true, 'oper_status' => 1, 'pvid' => 10]);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 2, 'name' => 'eth1/0/2', 'physical' => true, 'pvid' => 20, 'tagged_vlans' => [30]]);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 99, 'name' => 'vlan10', 'physical' => false]);
        DevicePort::create(['network_device_id' => $remote->id, 'if_index' => 1, 'name' => 'ge1', 'physical' => true]);
        Vlan::factory()->create(['number' => 10, 'name' => 'Bureaux', 'color' => '#22c55e']);
        WallOutlet::create(['name' => 'F3', 'location_id' => $floor->id, 'network_device_id' => $switch->id, 'port' => 'eth1/0/1']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->get(route('patch-plan'))->assertOk()->assertSee('Plan de brassage');

        Livewire::test(PatchPlan::class)
            ->assertSee(['sw-etage1', 'sw-agence', 'F3', 'Bureaux'])->assertDontSee('sans-ports')
            ->assertSeeHtml('--vlan: #22c55e; --ink: #ffffff')
            ->assertSeeHtml('--vlan: '.VlanColor::automatic(20))
            ->assertSeeHtml('href="'.route('devices.show', ['device' => $switch->id, 'port' => 1]).'"')
            ->assertDontSeeHtml('wire:click="patch(')
            ->set('location', (string) $site->id)
            ->assertSee('sw-etage1')->assertDontSee('sw-agence')
            ->assertSee('1 port brassé');
    }

    public function test_operators_patch_an_outlet_from_the_plan_and_the_change_is_audited(): void
    {
        $this->withoutVite();
        $switch = NetworkDevice::factory()->create();
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 12, 'name' => 'Gi1/0/12', 'physical' => true]);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 50, 'name' => 'Vlan1', 'physical' => false]);
        $patched = WallOutlet::create(['name' => 'B7', 'network_device_id' => $switch->id, 'port' => 'Gi1/0/12']);
        $free = WallOutlet::create(['name' => 'B8']);
        WallOutlet::create(['name' => 'B9', 'network_device_id' => NetworkDevice::factory()->create()->id, 'port' => 'ge1']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(PatchPlan::class)->call('patch', $switch->id, 12)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $plan = Livewire::test(PatchPlan::class)->assertSeeHtml('wire:click="patch('.$switch->id.', 12)"');
        Livewire::test(PatchPlan::class)->call('patch', $switch->id, 50)->assertNotFound();

        $plan->call('patch', $switch->id, 12)->assertSet('outlet', (string) $patched->id)
            ->assertSee('Brassage de Gi1/0/12')->assertViewHas('outletChoices', fn ($choices): bool => $choices->pluck('name')->sort()->values()->all() === ['B7', 'B8'])
            ->set('outlet', (string) $free->id)->call('save')
            ->assertHasNoErrors()->assertDispatched('nexora-toast', tone: 'success')->assertSet('deviceId', null);

        $this->assertNull($patched->fresh()->network_device_id);
        $this->assertSame([$switch->id, 'Gi1/0/12'], [$free->fresh()->network_device_id, $free->fresh()->port]);
        $this->assertSame(['network_device_id' => [null, $switch->id], 'port' => [null, 'Gi1/0/12']],
            AuditLog::where('action', 'updated')->where('subject_type', 'wall_outlet')->where('subject_id', $free->id)->sole()->changes);

        $plan->call('patch', $switch->id, 12)->set('outlet', '')->call('save');
        $this->assertNull($free->fresh()->network_device_id);

        $plan->call('patch', $switch->id, 12)->set('outlet', '999999')->call('save')->assertHasErrors('outlet');
    }

    public function test_a_port_that_does_not_reach_an_outlet_gets_a_free_destination(): void
    {
        $this->withoutVite();
        $switch = NetworkDevice::factory()->create(['name' => 'sw-accueil']);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 7, 'name' => 'Gi1/0/7', 'physical' => true]);
        $outlet = WallOutlet::create(['name' => 'C4', 'network_device_id' => $switch->id, 'port' => 'Gi1/0/7']);

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $plan = Livewire::test(PatchPlan::class)->call('patch', $switch->id, 7)->assertSet('mode', 'outlet')
            ->set('mode', 'text')->assertSee('Autre destination')
            ->set('destination', str_repeat('x', PortDestination::MAX_LENGTH + 1))->call('save')->assertHasErrors('destination')
            ->set('destination', '  Imprimante accueil  ')->call('save')->assertHasNoErrors()
            ->assertDispatched('nexora-toast', tone: 'success', message: 'Destination de Gi1/0/7 enregistrée.');

        $destination = PortDestination::sole();
        $this->assertSame([$switch->id, 'Gi1/0/7', 'Imprimante accueil'], [$destination->network_device_id, $destination->port, $destination->label]);
        $this->assertNull($outlet->fresh()->network_device_id, 'A port leads to one place: the outlet is freed.');
        $this->assertSame('Imprimante accueil · sw-accueil Gi1/0/7', AuditLog::where('action', 'created')->where('subject_type', 'port_destination')->sole()->subject_label);
        $plan->assertSeeHtml('patch-outlet is-free')->assertSee('Imprimante accueil')->assertSee('1 port brassé');

        $plan->call('patch', $switch->id, 7)->assertSet('mode', 'text')->assertSet('destination', 'Imprimante accueil')
            ->set('mode', 'outlet')->set('outlet', (string) $outlet->id)->call('save');
        $this->assertSame(0, PortDestination::count());
        $this->assertSame('Gi1/0/7', $outlet->fresh()->port);
        $this->assertSame(1, AuditLog::where('action', 'deleted')->where('subject_type', 'port_destination')->count());

        $plan->call('patch', $switch->id, 7)->set('mode', 'text')->set('destination', '')->call('save')
            ->assertDispatched('nexora-toast', tone: 'success', message: 'Port Gi1/0/7 libéré.');
        $this->assertNull($outlet->fresh()->network_device_id);
        $this->assertSame(0, PortDestination::count());
    }

    public function test_the_port_panel_records_a_free_destination_and_the_search_finds_it(): void
    {
        $this->withoutVite();
        $switch = NetworkDevice::factory()->create(['name' => 'sw-etage']);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 3, 'name' => 'ge3', 'physical' => true]);
        $outlet = WallOutlet::create(['name' => 'D1', 'network_device_id' => $switch->id, 'port' => 'ge3']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(DeviceDetail::class, ['device' => $switch])->call('select', 3)->set('destination', 'Borne Wi-Fi')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $page = Livewire::test(DeviceDetail::class, ['device' => $switch])->call('select', 3)->assertSet('outlet', (string) $outlet->id)
            ->set('destination', 'Borne Wi-Fi plafond')->assertSet('outlet', '')->assertSee('Borne Wi-Fi plafond');
        $this->assertNull($outlet->fresh()->network_device_id);
        $this->assertSame('Borne Wi-Fi plafond', PortDestination::sole()->label);

        Livewire::test(GlobalSearch::class)->set('query', 'wi-fi plaf')->assertSee('Borne Wi-Fi plafond')->assertSee('sw-etage · ge3')
            ->assertSeeHtml('href="'.route('devices.show', ['device' => $switch->id, 'port' => 3]).'"');

        $page->set('outlet', (string) $outlet->id)->assertSet('destination', '');
        $this->assertSame(0, PortDestination::count());
        $page->set('destination', 'Baie B')->set('destination', '');
        $this->assertSame(0, PortDestination::count());
    }
}
