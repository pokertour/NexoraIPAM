<?php

namespace Tests\Feature;

use App\Livewire\DeviceDetail;
use App\Livewire\Inventory;
use App\Models\DevicePort;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Models\Vlan;
use App\Support\VlanColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VlanColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_vlans_get_a_stable_automatic_colour_or_a_chosen_one(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(Inventory::class, ['section' => 'vlans'])
            ->call('open')->set('form.number', 20)->set('form.name', 'Serveurs')->set('form.color', '#123456')
            ->call('save')->assertHasErrors('form.color')
            ->set('form.color', '#22c55e')->call('save')->assertHasNoErrors()
            ->call('open')->set('form.number', 30)->set('form.name', 'VoIP')->set('form.color', '')->call('save')->assertHasNoErrors();

        $this->assertSame('#22c55e', Vlan::where('number', 20)->value('color'));
        $this->assertNull(Vlan::where('number', 30)->value('color'));
        $this->assertSame(VlanColor::automatic(30), Vlan::where('number', 30)->sole()->displayColor());
        $this->assertSame(VlanColor::automatic(30), VlanColor::automatic(30));
        $this->assertNotSame(VlanColor::automatic(30), VlanColor::automatic(31));
        $this->assertSame([20 => '#22c55e', 30 => VlanColor::automatic(30), 40 => VlanColor::automatic(40)], VlanColor::map([20, '30', 40, null, 20]));
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'subject_type' => 'vlan', 'subject_label' => '20 · Serveurs']);
    }

    public function test_the_front_panel_colours_ports_by_vlan_and_offers_a_highlight(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Vlan::factory()->create(['number' => 20, 'name' => 'Serveurs', 'color' => '#22c55e']);
        $device = NetworkDevice::factory()->create();
        DevicePort::create(['network_device_id' => $device->id, 'if_index' => 1, 'name' => 'ether1', 'physical' => true, 'pvid' => 20, 'media' => 'rj45']);
        DevicePort::create(['network_device_id' => $device->id, 'if_index' => 2, 'name' => 'sfp-sfpplus1', 'physical' => true, 'pvid' => 1, 'tagged_vlans' => [20, 30], 'media' => 'sfp']);

        Livewire::test(DeviceDetail::class, ['device' => $device])
            ->assertSeeHtml('class="switch-port-vlan" style="--vlan: #22c55e"')
            ->assertSeeHtml('data-vlans="1 20 30"')
            ->assertSeeHtml('data-media="sfp"')
            ->assertSee('Serveurs')
            ->assertSeeHtml('--vlan: '.VlanColor::automatic(30));
    }
}
