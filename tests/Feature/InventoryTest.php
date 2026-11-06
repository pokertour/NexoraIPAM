<?php

namespace Tests\Feature;

use App\Livewire\Inventory;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cidrs_and_addresses_are_normalized_and_checked_against_the_subnet(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $vlan = Vlan::factory()->create();

        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('open')
            ->set('form.cidr', '2001:0db8:abcd::5/48')
            ->set('form.name', 'Réseau de test')
            ->set('form.vlan_id', $vlan->id)
            ->call('save')->assertHasNoErrors();
        $subnet = Subnet::where('cidr', '2001:db8:abcd::/48')->sole();

        Livewire::test(Inventory::class, ['section' => 'addresses'])
            ->call('open')->set('form.subnet_id', $subnet->id)
            ->set('form.address', '2001:db8:abce::1')->call('save')->assertHasErrors('form.address')
            ->set('form.address', '2001:0db8:abcd:0000::1')->call('save')->assertHasNoErrors()
            ->call('open')->set('form.subnet_id', $subnet->id)
            ->set('form.address', '2001:db8:abcd::1')->call('save')->assertHasErrors(['form.address' => 'unique']);

        $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8:abcd::1', 'subnet_id' => $subnet->id]);

        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('open', $subnet->id)->set('form.cidr', '192.0.2.3/24')->call('save')->assertHasErrors('form.cidr')
            ->call('close')->call('confirmDelete', $subnet->id)->call('delete')->assertHasErrors('delete');

        $this->assertDatabaseHas('subnets', ['id' => $subnet->id, 'cidr' => '2001:db8:abcd::/48']);
    }

    public function test_agencies_scope_inventory_and_capacity_including_child_locations(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $agency = Location::factory()->create(['name' => 'Lyon']);
        $other = Location::factory()->create(['name' => 'Lille']);
        $office = Location::factory()->create(['kind' => 'office', 'parent_id' => $agency->id]);
        $vlan = Vlan::factory()->create(['number' => 10, 'location_id' => $agency->id]);
        Vlan::factory()->create(['number' => 10, 'location_id' => $other->id]);

        Livewire::test(Inventory::class, ['section' => 'locations'])->call('open')
            ->set('form.name', 'Étage 1')->set('form.kind', 'floor')->set('form.parent_id', $agency->id)
            ->call('save')->assertHasErrors('form.parent_id')
            ->set('form.parent_id', $office->id)->call('save')->assertHasNoErrors();
        $floor = Location::where('name', 'Étage 1')->sole();
        Subnet::factory()->create(['name' => 'Réseau de Lille', 'cidr' => '192.0.2.0/24', 'location_id' => $other->id]);

        Livewire::test(Inventory::class, ['section' => 'subnets'])->call('open')
            ->set('form.name', 'Réseau de Lyon')->set('form.cidr', '192.0.2.0/24')
            ->set('form.location_id', $floor->id)->set('form.vlan_id', $vlan->id)
            ->call('save')->assertHasNoErrors();

        $subnet = Subnet::where('location_id', $floor->id)->sole();
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.11', 'status' => 'reserved']);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.12', 'status' => 'available']);

        $this->get(route('dashboard', ['location' => $agency->id]))
            ->assertOk()->assertSee('1 / 254 IPv4')->assertSee('Réseau de Lyon')->assertDontSee('Réseau de Lille');

        $this->get(route('subnets', ['location' => $agency->id]))
            ->assertOk()->assertSee('Réseau de Lyon')->assertDontSee('Réseau de Lille');

        Livewire::test(Inventory::class, ['section' => 'subnets'])->call('open', $subnet->id)
            ->set('form.location_id', $other->id)->set('form.cidr', '192.0.3.0/24')
            ->call('save')->assertHasErrors('form.cidr')
            ->set('form.cidr', '192.0.2.0/24')->call('save')->assertHasErrors('form.cidr')
            ->set('form.location_id', null)->call('save')->assertHasErrors('form.vlan_id');
    }

    public function test_free_ranges_and_subdivision_support_prefilled_creation_and_respect_delegation(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $parent = Subnet::factory()->create(['cidr' => '192.0.2.0/28']);
        IpAddress::factory()->for($parent)->create(['address' => '192.0.2.2']);
        IpAddress::factory()->for($parent)->create(['address' => '192.0.2.10']);

        $this->get(route('addresses', ['subnet' => $parent->id]))->assertOk()
            ->assertSee('Carte des adresses')->assertDontSee('@js(', false)->assertSeeInOrder(['192.0.2.1', '192.0.2.2', '192.0.2.3 – 192.0.2.9', '192.0.2.10']);
        Livewire::test(Inventory::class, ['section' => 'addresses'])->call('openAddress', $parent->id, '192.0.2.3')
            ->assertSet('form.subnet_id', $parent->id)->assertSet('form.address', '192.0.2.3')->assertSeeHtml('data-model="form.status"')
            ->call('save')->assertHasNoErrors();

        $this->get(route('subnets', ['space' => $parent->id]))->assertOk()->assertSee('192.0.2.12/30');
        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('openAllocation', $parent->id, '192.0.2.8/30')->assertSet('editing', false)
            ->call('openAllocation', $parent->id, '192.0.2.12/30')->assertSet('form.cidr', '192.0.2.12/30')
            ->set('form.name', 'Réseau délégué')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('subnets', ['cidr' => '192.0.2.12/30', 'parent_subnet_id' => $parent->id]);
        $this->assertSame(11, $parent->fresh()->ipv4Capacity());
        $this->get(route('addresses', ['subnet' => $parent->id]))->assertOk()
            ->assertDontSee('192.0.2.11 – 192.0.2.14');
        Livewire::test(Inventory::class, ['section' => 'addresses'])->call('openAddress', $parent->id, '192.0.2.11')
            ->set('form.address', '192.0.2.13')->call('save')->assertHasErrors('form.address');
    }

    public function test_an_empty_large_ipv6_network_is_shown_as_one_exact_free_range(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $subnet = Subnet::factory()->create(['cidr' => '2001:db8::/64']);

        $this->get(route('addresses', ['subnet' => $subnet->id]))->assertOk()
            ->assertSee('2001:db8:: – 2001:db8::ffff:ffff:ffff:ffff')
            ->assertSee('18446744073709551616 adresses libres')->assertDontSee('Attribuer');
    }
}
