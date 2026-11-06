<?php

namespace Tests\Feature;

use App\Livewire\Inventory;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Models\User;
use App\Support\AddressAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubnetNetworkSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_settings_are_normalized_and_checked_against_the_range(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('open')->set('form.name', 'Bureaux')->set('form.cidr', '192.0.2.0/24')
            ->set('form.gateway', '198.51.100.1')->set('form.dns_servers', '192.0.2.53 nope')
            ->set('form.dhcp_start', '192.0.2.200')->set('form.dhcp_end', '192.0.2.100')
            ->call('save')->assertHasErrors(['form.dns_servers'])
            ->set('form.dns_servers', '192.0.2.53;2001:0db8::53')->call('save')->assertHasErrors(['form.gateway'])
            ->set('form.gateway', '192.0.2.1')->call('save')->assertHasErrors(['form.dhcp_end'])
            ->set('form.dhcp_end', '192.0.2.250')->set('form.domain', 'LAN.Example.test.')->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('subnets', [
            'cidr' => '192.0.2.0/24', 'gateway' => '192.0.2.1', 'dns_servers' => '192.0.2.53, 2001:db8::53',
            'domain' => 'lan.example.test', 'dhcp_start' => '192.0.2.200', 'dhcp_end' => '192.0.2.250',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'subject_type' => 'subnet']);
    }

    public function test_the_next_free_address_skips_the_gateway_inventory_and_dhcp_pool(): void
    {
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/29', 'gateway' => '192.0.2.1', 'dhcp_start' => '192.0.2.4', 'dhcp_end' => '192.0.2.5']);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.2']);
        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.3']);
        $availability = app(AddressAvailability::class);

        $this->assertSame('192.0.2.6', $availability->nextFree($subnet));

        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.6']);
        $this->assertNull($availability->nextFree($subnet));

        $ipv6 = Subnet::factory()->create(['cidr' => '2001:db8::/64', 'gateway' => '2001:db8::']);
        IpAddress::factory()->for($ipv6)->create(['address' => '2001:db8::1']);
        $this->assertSame('2001:db8::2', $availability->nextFree($ipv6));
    }

    public function test_operators_open_the_address_form_on_the_next_free_address(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/30', 'gateway' => '192.0.2.1']);

        $this->get(route('addresses', ['subnet' => $subnet->id]))->assertOk()->assertSee('Prochaine IP libre')->assertSee('Passerelle');

        $component = Livewire::test(Inventory::class, ['section' => 'addresses'])
            ->call('openNextFree', $subnet->id)->assertSet('editing', true)->assertSet('form.address', '192.0.2.2');

        IpAddress::factory()->for($subnet)->create(['address' => '192.0.2.2']);
        $component->call('close')->call('openNextFree', $subnet->id)->assertSet('editing', false)
            ->assertDispatched('nexora-toast', tone: 'error');
    }
}
