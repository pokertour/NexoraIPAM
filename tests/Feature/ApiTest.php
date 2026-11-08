<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\DevicePort;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Subnet;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_need_the_right_ability_role_and_an_active_account(): void
    {
        $this->getJson('/api/v1/subnets')->assertUnauthorized();
        $this->get('/api/v1/subnets')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);

        Sanctum::actingAs($operator = User::factory()->create(['role' => 'operator']), ['read']);
        $this->getJson('/api/v1/subnets')->assertOk();
        $this->postJson('/api/v1/vlans', ['number' => 10, 'name' => 'Users'])->assertForbidden()->assertJson(['message' => 'Ce jeton ne permet pas cette action.']);

        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']), ['read', 'write']);
        $this->postJson('/api/v1/vlans', ['number' => 10, 'name' => 'Users'])->assertForbidden();

        $operator->update(['active' => false]);
        Sanctum::actingAs($operator, ['read', 'write']);
        $this->getJson('/api/v1/subnets')->assertForbidden()->assertJson(['message' => 'Ce compte ne peut pas utiliser l’API.']);
    }

    public function test_subnets_are_created_validated_updated_and_protected_from_deletion(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'name' => 'Robot Ansible']);
        Sanctum::actingAs($operator, ['read', 'write']);
        $location = Location::factory()->create(['name' => 'Lyon']);
        Tag::factory()->create(['name' => 'production']);
        CustomField::factory()->create(['key' => 'owner', 'label' => 'Responsable', 'required' => true]);

        $this->postJson('/api/v1/subnets', ['name' => 'Serveurs', 'cidr' => '192.0.2.77/24', 'gateway' => '198.51.100.1', 'custom_fields' => ['owner' => 'NOC']])
            ->assertUnprocessable()->assertJsonValidationErrors('gateway');
        $this->postJson('/api/v1/subnets', ['name' => 'Serveurs', 'cidr' => '192.0.2.0/24'])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_fields.owner');
        $this->postJson('/api/v1/subnets', ['name' => 'Serveurs', 'cidr' => 'pas-un-réseau', 'custom_fields' => ['owner' => 'NOC']])
            ->assertUnprocessable()->assertJsonValidationErrors('cidr');
        $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/subnets', ['name' => 'Serveurs', 'cidr' => 'nope', 'custom_fields' => ['owner' => 'NOC']])
            ->assertJsonPath('errors.cidr.0', 'Enter a valid IPv4 or IPv6 network in CIDR notation.');
        $this->withHeaders(['Accept-Language' => 'fr']);

        $id = $this->postJson('/api/v1/subnets', [
            'name' => 'Serveurs', 'cidr' => '192.0.2.77/24', 'location_id' => $location->id, 'gateway' => '192.0.2.1',
            'dns_servers' => '192.0.2.53, 2001:0db8::53', 'dhcp_start' => '192.0.2.200', 'dhcp_end' => '192.0.2.250',
            'tags' => ['production'], 'custom_fields' => ['owner' => 'NOC'],
        ])->assertCreated()->assertJsonPath('data.cidr', '192.0.2.0/24')
            ->assertJsonPath('data.dns_servers', ['192.0.2.53', '2001:db8::53'])
            ->assertJsonPath('data.location.path', 'Lyon')->assertJsonPath('data.tags', ['production'])
            ->assertJsonPath('data.custom_fields.owner', 'NOC')->json('data.id');

        $this->postJson('/api/v1/subnets', ['name' => 'Doublon', 'cidr' => '192.0.2.0/24', 'location_id' => $location->id, 'custom_fields' => ['owner' => 'NOC']])
            ->assertUnprocessable()->assertJsonValidationErrors('cidr');
        $this->patchJson("/api/v1/subnets/{$id}", ['name' => 'Serveurs Lyon'])->assertOk()->assertJsonPath('data.name', 'Serveurs Lyon')->assertJsonPath('data.custom_fields.owner', 'NOC');
        $this->getJson('/api/v1/subnets?search=Lyon')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);

        IpAddress::factory()->create(['subnet_id' => $id, 'address' => '192.0.2.10']);
        $this->deleteJson("/api/v1/subnets/{$id}")->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'subject_type' => 'subnet', 'user_id' => $operator->id, 'actor' => 'Robot Ansible']);
    }

    public function test_the_next_free_address_is_reserved_in_one_call(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'operator']), ['read', 'write']);
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/30', 'gateway' => '192.0.2.1']);

        $this->postJson("/api/v1/subnets/{$subnet->id}/next-free", ['hostname' => 'vm-web-01', 'status' => 'reserved'])
            ->assertCreated()->assertJsonPath('data.address', '192.0.2.2')->assertJsonPath('data.status', 'reserved')->assertJsonPath('data.hostname', 'vm-web-01');
        $this->postJson("/api/v1/subnets/{$subnet->id}/next-free")->assertStatus(409);
        $this->getJson("/api/v1/subnets/{$subnet->id}/addresses")->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'subject_type' => 'ip_address', 'subject_label' => '192.0.2.2']);
    }

    public function test_addresses_and_vlans_follow_the_inventory_rules(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['read', 'write']);
        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/24']);
        $other = Subnet::factory()->create(['cidr' => '198.51.100.0/24']);

        $this->postJson('/api/v1/addresses', ['subnet_id' => $subnet->id, 'address' => '198.51.100.4'])->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->postJson('/api/v1/addresses', ['subnet_id' => $subnet->id, 'address' => '192.0.2.255'])->assertUnprocessable();
        $id = $this->postJson('/api/v1/addresses', ['subnet_id' => $subnet->id, 'address' => '192.0.2.4', 'hostname' => 'printer'])
            ->assertCreated()->assertJsonPath('data.status', 'used')->json('data.id');
        $this->postJson('/api/v1/addresses', ['subnet_id' => $subnet->id, 'address' => '192.0.2.4'])->assertUnprocessable();

        IpAddress::whereKey($id)->update(['mac_address' => '00:11:22:33:44:55']);
        $this->getJson('/api/v1/addresses?mac=0011.2233.4455')->assertOk()->assertJsonPath('data.0.address', '192.0.2.4');
        $this->patchJson("/api/v1/addresses/{$id}", ['subnet_id' => $other->id, 'address' => '198.51.100.4'])->assertOk()
            ->assertJsonPath('data.subnet_id', $other->id)->assertJsonPath('data.mac_address', null);
        $this->deleteJson("/api/v1/addresses/{$id}")->assertNoContent();

        $this->postJson('/api/v1/vlans', ['number' => 20, 'name' => 'Serveurs', 'color' => '#123456'])->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->postJson('/api/v1/vlans', ['number' => 20, 'name' => 'Serveurs', 'color' => '#22c55e'])->assertCreated()->assertJsonPath('data.color', '#22c55e');
        $this->postJson('/api/v1/vlans', ['number' => 20, 'name' => 'Doublon'])->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->getJson('/api/v1/vlans?number=20')->assertOk()->assertJsonPath('data.0.name', 'Serveurs');
    }

    public function test_devices_locations_search_and_account_are_readable(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        Sanctum::actingAs($user, ['read']);
        $device = NetworkDevice::factory()->create(['name' => 'sw-rdc', 'credentials' => ['community' => 'secret-community']]);
        DevicePort::create(['network_device_id' => $device->id, 'if_index' => 1, 'name' => 'sfp-sfpplus1', 'physical' => true, 'media' => 'sfp', 'pvid' => 10, 'tagged_vlans' => [20], 'oper_status' => 1]);
        Location::factory()->create(['name' => 'Paris']);
        Subnet::factory()->create(['name' => 'Campus', 'cidr' => '10.0.0.0/16']);

        $this->getJson("/api/v1/devices/{$device->id}")->assertOk()->assertJsonPath('data.ports.0.media', 'sfp')
            ->assertJsonPath('data.ports.0.tagged_vlans', [20])->assertJsonPath('data.ports.0.state', 'up')->assertDontSee('secret-community');
        $this->getJson('/api/v1/locations')->assertOk()->assertJsonPath('data.0.path', 'Paris');
        $this->getJson('/api/v1/search?q=10.0.4.1')->assertOk()->assertJsonPath('data.Sous-réseaux.0.label', 'Campus');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.role', 'viewer')->assertJsonPath('data.token.abilities', ['read']);
    }

    public function test_users_create_and_revoke_tokens_from_their_profile(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['role' => 'operator']);
        $this->actingAs($user);

        $this->post(route('profile.tokens.store'), ['token_name' => 'Ansible', 'token_write' => '1', 'token_days' => 30])->assertRedirect(route('profile'));
        $token = $user->tokens()->sole();
        $page = $this->get(route('profile'))->assertOk()->assertSee('Ansible')->assertSee('Lecture et écriture');
        preg_match('/\b(\d+\|[A-Za-z0-9]{40,})\b/', $page->getContent(), $plain);
        $this->assertNotEmpty($plain);
        $this->get(route('profile'))->assertDontSee($plain[1]);
        $this->assertSame(['read', 'write'], $token->abilities);
        $this->assertTrue($token->expires_at->isFuture());

        auth()->forgetGuards();
        $this->withToken($plain[1])->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.token.name', 'Ansible');

        $this->actingAs($user)->delete(route('profile.tokens.destroy', $token->id))->assertRedirect(route('profile'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(['api_token.created', 'api_token.revoked'], AuditLog::where('action', 'like', 'api_token.%')->orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString($plain[1], AuditLog::pluck('changes')->toJson());
    }
}
