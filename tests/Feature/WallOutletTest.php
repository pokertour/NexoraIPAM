<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\GlobalSearch;
use App\Livewire\WallOutlets;
use App\Models\AuditLog;
use App\Models\DevicePort;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Models\WallOutlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WallOutletTest extends TestCase
{
    use RefreshDatabase;

    public function test_operators_create_a_series_of_outlets_and_existing_names_are_skipped(): void
    {
        $this->withoutVite();
        $office = Location::factory()->create(['name' => 'Bureaux']);
        WallOutlet::create(['location_id' => $office->id, 'name' => 'A03']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->get(route('outlets'))->assertOk()->assertSee('Prises murales');
        Livewire::test(WallOutlets::class)->call('open', 'batch')->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        Livewire::test(WallOutlets::class)
            ->call('open', 'batch')->set('form.location_id', $office->id)->set('form.prefix', 'A')
            ->set('form.start', 5)->set('form.end', 2)->call('save')->assertHasErrors('form.end')
            ->set('form.start', 1)->set('form.end', 501)->call('save')->assertHasErrors('form.end')
            ->set('form.end', 24)->set('form.width', 2)->assertViewHas('preview', ['count' => 24, 'first' => 'A01', 'last' => 'A24'])
            ->call('save')->assertHasNoErrors()
            ->assertDispatched('nexora-toast', tone: 'success', message: '23 prises créées. 1 existait déjà.');

        $this->assertSame(24, WallOutlet::where('location_id', $office->id)->count());
        $this->assertSame(['A01', 'A10', 'A24'], WallOutlet::whereIn('name', ['A01', 'A10', 'A24'])->orderBy('name')->pluck('name')->all());
        $this->assertSame(24, AuditLog::where('action', 'created')->where('subject_type', 'wall_outlet')->count());
        $this->assertSame(['A1', 'A2'], WallOutlet::batchNames('A', 1, 2));
    }

    public function test_an_outlet_is_patched_to_one_port_of_a_device(): void
    {
        $this->withoutVite();
        $switch = NetworkDevice::factory()->create(['name' => 'sw-etage']);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 12, 'name' => 'Gi1/0/12', 'physical' => true, 'oper_status' => 1, 'pvid' => 20]);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 13, 'name' => 'Gi1/0/13', 'physical' => true]);
        $first = WallOutlet::create(['name' => 'A1']);
        $second = WallOutlet::create(['name' => 'A2']);
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(WallOutlets::class)
            ->call('open', 'outlet', $first->id)->set('form.network_device_id', $switch->id)
            ->call('save')->assertHasErrors('form.port')
            ->set('form.port', 'Gi1/0/99')->call('save')->assertHasErrors('form.port')
            ->set('form.port', 'Gi1/0/12')->call('save')->assertHasNoErrors()
            ->call('open', 'outlet', $second->id)->set('form.network_device_id', $switch->id)->set('form.port', 'Gi1/0/12')
            ->call('save')->assertHasErrors('form.port')
            ->set('form.name', 'A1')->call('save')->assertHasErrors('form.name');

        $this->assertSame([$switch->id, 'Gi1/0/12'], [$first->fresh()->network_device_id, $first->fresh()->port]);
        $this->assertSame(['network_device_id' => [null, $switch->id], 'port' => [null, 'Gi1/0/12']], AuditLog::where('action', 'updated')->where('subject_id', $first->id)->sole()->changes);

        Livewire::test(WallOutlets::class)->assertSee('sw-etage')->assertSee('Gi1/0/12')
            ->set('state', 'free')->assertSee('A2')->assertDontSee('sw-etage')
            ->set('state', '')->set('search', 'etage')->assertSee('A1')->assertDontSee('A2');
        Livewire::test(GlobalSearch::class)->set('query', 'A1')->assertSee('sw-etage Gi1/0/12');
    }

    public function test_the_port_panel_shows_and_changes_the_patched_outlet(): void
    {
        $this->withoutVite();
        $switch = NetworkDevice::factory()->create();
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 12, 'name' => 'Gi1/0/12', 'physical' => true]);
        DevicePort::create(['network_device_id' => $switch->id, 'if_index' => 13, 'name' => 'Gi1/0/13', 'physical' => true]);
        $patched = WallOutlet::create(['name' => 'B7', 'network_device_id' => $switch->id, 'port' => 'Gi1/0/12']);
        $free = WallOutlet::create(['name' => 'B8']);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Livewire::test(DeviceDetail::class, ['device' => $switch])->call('select', 12)->assertSee('B7')
            ->set('outlet', (string) $free->id)->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $page = Livewire::test(DeviceDetail::class, ['device' => $switch])->call('select', 12)->assertSet('outlet', (string) $patched->id)
            ->set('outlet', (string) $free->id)->assertDispatched('nexora-toast', tone: 'success');
        $this->assertSame([null, null], [$patched->fresh()->network_device_id, $patched->fresh()->port]);
        $this->assertSame('Gi1/0/12', $free->fresh()->port);

        $page->call('select', 13)->set('outlet', (string) $free->id);
        $this->assertSame('Gi1/0/13', $free->fresh()->port);
        $page->set('outlet', '');
        $this->assertNull($free->fresh()->network_device_id);
    }

    public function test_selected_outlets_are_unpatched_or_deleted_together(): void
    {
        $switch = NetworkDevice::factory()->create();
        $outlets = collect(['C1', 'C2', 'C3'])->map(fn (string $name, int $index): WallOutlet => WallOutlet::create(['name' => $name, 'network_device_id' => $switch->id, 'port' => 'p'.$index]));
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(WallOutlets::class)
            ->set('selected', [(string) $outlets[0]->id, (string) $outlets[1]->id])->call('unassignSelected')
            ->assertDispatched('nexora-toast', message: '2 prises débrassées.')
            ->call('confirmDelete')->call('delete')->assertDispatched('nexora-toast', message: '2 prises supprimées.')->assertSet('selected', []);

        $this->assertSame(['C3'], WallOutlet::pluck('name')->all());
        $this->assertSame(2, AuditLog::where('action', 'deleted')->where('subject_type', 'wall_outlet')->count());
    }
}
