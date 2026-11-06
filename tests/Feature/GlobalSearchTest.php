<?php

namespace Tests\Feature;

use App\Livewire\GlobalSearch;
use App\Models\DevicePort;
use App\Models\DevicePortMac;
use App\Models\IpAddress;
use App\Models\NetworkDevice;
use App\Models\Subnet;
use App\Models\Tag;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_search_is_available_on_every_workspace_page(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $this->get(route('dashboard'))->assertOk()->assertSee('Ctrl K')->assertSeeHtml('id="global-search"');
    }

    public function test_an_ip_finds_its_record_and_every_subnet_containing_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $parent = Subnet::factory()->create(['name' => 'Campus', 'cidr' => '10.0.0.0/16']);
        $child = Subnet::factory()->create(['name' => 'Étage 2', 'cidr' => '10.0.2.0/24']);
        Subnet::factory()->create(['name' => 'Invités', 'cidr' => '192.168.0.0/24']);
        IpAddress::factory()->for($child)->create(['address' => '10.0.2.15', 'hostname' => 'imprimante-2']);

        $groups = Livewire::test(GlobalSearch::class)->set('query', '10.0.2.15')
            ->assertSee('imprimante-2')->assertSee('Campus')->assertSee('Étage 2')->assertDontSee('Invités')
            ->instance()->results();

        $this->assertSame(['Étage 2', 'Campus'], array_column($groups['Sous-réseaux'], 'label'));
        $this->assertSame(route('addresses', ['subnet' => $child->id, 'search' => '10.0.2.15']), $groups['Adresses IP'][0]['url']);
        $this->assertSame(route('addresses', ['subnet' => $parent->id]), $groups['Sous-réseaux'][1]['url']);
    }

    public function test_a_mac_finds_the_switch_port_where_it_is_plugged(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $device = NetworkDevice::factory()->create(['name' => 'sw-rdc']);
        $port = DevicePort::create(['network_device_id' => $device->id, 'if_index' => 1013, 'name' => 'GigabitEthernet1/0/13', 'physical' => true]);
        DevicePortMac::create(['network_device_id' => $device->id, 'device_port_id' => $port->id, 'mac_address' => '00:15:5d:00:13:01', 'vlan' => 20, 'seen_at' => now()]);

        Livewire::test(GlobalSearch::class)->set('query', '0015.5d00.1301')
            ->assertSee('sw-rdc · GigabitEthernet1/0/13')->assertSee('VLAN 20')
            ->assertSeeHtml(e(route('devices.show', ['device' => $device->id, 'port' => 1013])));
    }

    public function test_names_vlans_and_tags_are_searched_and_short_queries_are_ignored(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        Vlan::factory()->create(['number' => 120, 'name' => 'Serveurs']);
        Tag::factory()->create(['name' => 'serveurs-critiques']);
        $subnet = Subnet::factory()->create(['name' => 'DMZ']);
        $subnet->tags()->attach(Tag::sole());

        Livewire::test(GlobalSearch::class)
            ->set('query', 's')->assertSee('Saisissez au moins deux caractères')
            ->set('query', 'serveurs')->assertSee('120 · Serveurs')->assertSee('#serveurs-critiques')->assertSee('DMZ')
            ->set('query', '120')->assertSee('120 · Serveurs')
            ->set('query', 'introuvable')->assertSee('Aucun résultat');
    }
}
