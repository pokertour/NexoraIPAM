<?php

namespace Tests\Feature;

use App\Livewire\NetworkTools;
use App\Models\User;
use App\Support\MacAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class NetworkToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cidr_calculator_supports_point_to_point_and_large_ipv6_networks(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NetworkTools::class)
            ->set('cidr', '192.0.2.1/31')->call('calculate')->assertHasNoErrors()
            ->assertSet('network.first', '192.0.2.0')->assertSet('network.last', '192.0.2.1')
            ->assertSet('network.broadcast', null)->assertSet('network.capacity', '2')
            ->set('cidr', '2001:db8::5/64')->call('calculate')->assertHasNoErrors()
            ->assertSet('network.cidr', '2001:db8::/64')->assertSet('network.capacity', '18446744073709551616')
            ->set('cidr', '2001:db8::5/129')->call('calculate')->assertHasErrors('cidr');
    }

    public function test_mac_lookup_prefers_the_longest_ieee_prefix_and_handles_local_addresses(): void
    {
        $disk = Storage::fake('local');
        $disk->put('oui.csv', "MA-L,001122,Parent vendor\n");
        $disk->put('oui36.csv', "MA-S,001122334,Specific vendor\n");

        $registry = app(MacAddress::class);
        $this->assertSame('Specific vendor', $registry->lookup(MacAddress::normalize('0011.2233.4455'), $disk->path(''))['organization']);

        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        $this->get(route('tools', ['mac' => '02-11-22-33-44-55']))
            ->assertOk()->assertSee('02:11:22:33:44:55')->assertSee('Adresse administrée localement');
    }
}
