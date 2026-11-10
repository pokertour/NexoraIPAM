<?php

namespace Tests\Feature;

use App\Livewire\GlobalSearch;
use App\Models\Alert;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_is_grouped_and_hides_administration_from_other_roles(): void
    {
        $this->withoutVite();
        Alert::create(['type' => 'device_down', 'severity' => 'critical', 'subject_type' => 'network_device', 'subject_id' => 1, 'subject_label' => 'sw', 'details' => [], 'last_seen_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $page = $this->get(route('patch-plan'))->assertOk()
            ->assertSee(['Adressage', 'Infrastructure', 'Diagnostic'])
            ->assertSee('<span class="nav-badge" aria-label="1 alerte active">1</span>', false)
            ->assertDontSee('nexora-nav-admin')
            ->assertDontSee('data-label="Plan de brassage"', false)
            ->assertDontSee('data-label="À propos"', false)
            ->assertSee('href="'.route('about').'"', false)
            ->getContent();
        $this->assertActiveLink($page, route('outlets'), 'nav-link', 'Brassage');
        $this->assertActiveLink($page, route('patch-plan'), 'page-tab', 'Plan de brassage');
        $this->assertSame(2, substr_count($page, 'aria-current="page"'), 'Only the sidebar entry and the current tab are current.');

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $settings = $this->get(route('api-tokens'))->assertOk()
            ->assertSee('nexora-nav-admin')
            ->assertSee(['Connexion et e-mails', 'Étiquettes et champs'])
            ->assertDontSee('data-label="Étiquettes et champs"', false)
            ->getContent();
        $this->assertActiveLink($settings, route('preferences'), 'nav-link', 'Paramètres');
        $this->assertActiveLink($settings, route('api-tokens'), 'page-tab', 'Jetons d’API');
    }

    public function test_related_pages_share_tabs(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        $outlets = $this->get(route('outlets'))->assertOk()->assertSee('href="'.route('patch-plan').'" wire:navigate class="page-tab" ', false)->getContent();
        $this->assertActiveLink($outlets, route('outlets'), 'page-tab', 'Prises murales');
        $this->get(route('racks'))->assertOk()->assertDontSee('class="page-tab', false);
        $this->assertNull(Navigation::tabsOf('racks'));
    }

    public function test_the_global_search_offers_pages_the_user_may_open(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']));
        $this->assertSame([], Navigation::search('smtp'));
        $this->assertSame(['Prises murales', 'Plan de brassage'], array_column(Navigation::search('brassage'), 'label'));
        Livewire::test(GlobalSearch::class)->set('query', 'topolo')->assertSee('Topologie')->assertSeeHtml('href="'.route('topology').'"');

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertSame([['label' => 'Connexion et e-mails', 'detail' => 'Administration · Paramètres', 'url' => route('preferences'), 'icon' => 'preferences']], Navigation::search('SMTP'));
        Livewire::test(GlobalSearch::class)->set('query', 'jetons')->assertSee('Jetons d’API');
    }

    private function assertActiveLink(string $html, string $url, string $class, string $label): void
    {
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote($url, '#').'" wire:navigate class="'.$class.' active"[^>]*aria-current="page"[^>]*>\s*(?:<svg.*?</svg>\s*<span class="nav-text">)?'.preg_quote($label, '#').'<#s', $html);
    }
}
