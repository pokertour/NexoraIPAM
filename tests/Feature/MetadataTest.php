<?php

namespace Tests\Feature;

use App\Livewire\Inventory;
use App\Livewire\Metadata;
use App\Models\AuditLog;
use App\Models\CustomField;
use App\Models\Subnet;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrators_manage_tags_and_custom_fields(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs(User::factory()->create(['role' => 'operator']))->get(route('metadata'))->assertForbidden();

        Livewire::actingAs($admin)->test(Metadata::class)
            ->call('open', 'tag')->set('form.name', 'Production')->set('form.color', 'red')->call('save')->assertHasNoErrors()
            ->call('open', 'tag')->set('form.name', 'Production')->call('save')->assertHasErrors(['form.name' => 'unique'])
            ->call('open', 'field')->set('form.label', 'Environnement')->set('form.applies_to', 'subnets')->set('form.type', 'select')
            ->call('save')->assertHasErrors('form.options')
            ->set('form.options', 'prod, recette, prod')->call('save')->assertHasNoErrors()
            ->call('open', 'field')->set('form.label', 'Environnement')->set('form.applies_to', 'subnets')->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('tags', ['name' => 'Production', 'color' => 'red']);
        $this->assertSame(['prod', 'recette'], CustomField::where('key', 'environnement')->sole()->options);
        $this->assertDatabaseHas('custom_fields', ['key' => 'environnement_2', 'type' => 'text']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'subject_type' => 'tag', 'subject_label' => 'Production']);
        $this->actingAs($admin)->get(route('metadata'))->assertOk()->assertSee('Production')->assertSee('environnement_2');
    }

    public function test_inventory_entries_receive_tags_and_validated_custom_values(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $production = Tag::factory()->create(['name' => 'production']);
        $backup = Tag::factory()->create(['name' => 'sauvegarde']);
        CustomField::factory()->create(['label' => 'Environnement', 'key' => 'env', 'type' => 'select', 'options' => ['prod', 'recette'], 'required' => true]);
        CustomField::factory()->create(['label' => 'Baies', 'key' => 'racks', 'type' => 'number']);
        CustomField::factory()->create(['label' => 'Contrat', 'key' => 'contract', 'applies_to' => 'vlans']);

        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('open')->set('form.name', 'Cœur de réseau')->set('form.cidr', '192.0.2.0/24')
            ->set('form.tags', [(string) $production->id])->set('form.custom.racks', 'douze')
            ->call('save')->assertHasErrors(['form.custom.env', 'form.custom.racks'])
            ->set('form.custom.env', 'prod')->set('form.custom.racks', '12')->set('form.custom.contract', 'ignored')
            ->call('save')->assertHasNoErrors();

        $subnet = Subnet::sole();
        $this->assertEquals(['env' => 'prod', 'racks' => 12], $subnet->custom_values);
        $this->assertIsInt($subnet->custom_values['racks']);
        $this->assertSame(['production'], $subnet->tags->pluck('name')->all());

        Livewire::test(Inventory::class, ['section' => 'subnets'])
            ->call('open', $subnet->id)->assertSet('form.tags', [(string) $production->id])->assertSet('form.custom.env', 'prod')
            ->set('form.tags', [(string) $backup->id])->call('save')->assertHasNoErrors();
        $this->assertSame(['tags' => [['production'], ['sauvegarde']]], AuditLog::where('action', 'updated')->latest('id')->first()->changes);

        Subnet::factory()->create(['name' => 'Invités', 'cidr' => '198.51.100.0/24']);
        $this->get(route('subnets', ['tag' => $backup->id]))->assertOk()->assertSee('Cœur de réseau')->assertDontSee('Invités');
        Livewire::test(Inventory::class, ['section' => 'subnets'])->set('search', 'sauvegarde')->assertSee('Cœur de réseau')->assertDontSee('Invités')
            ->set('search', 'prod')->assertSee('Cœur de réseau')->assertDontSee('Invités');
    }
}
