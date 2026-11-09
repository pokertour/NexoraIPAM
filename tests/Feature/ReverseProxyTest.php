<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReverseProxyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const FORWARDED = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'ipam.example.com', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '198.51.100.20'];

    public function test_a_proxy_on_a_private_network_keeps_links_in_https_and_the_client_ip(): void
    {
        $this->withoutVite();
        $behindProxy = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])->withHeaders(self::FORWARDED);

        $behindProxy->get('/')->assertRedirect('https://ipam.example.com/auth');
        $behindProxy->get('/auth')->assertOk()->assertSee('https://ipam.example.com/livewire', false)->assertDontSee('http://ipam.example.com', false);

        // The client address, used by the audit log and the rate limits, is the one the proxy saw.
        Route::get('/_client-ip', fn (Request $request): string => (string) $request->ip());
        $behindProxy->get('/_client-ip')->assertSeeText('198.51.100.20');
    }

    public function test_forwarded_headers_from_an_untrusted_address_are_ignored(): void
    {
        $direct = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->withHeaders(self::FORWARDED);

        $this->assertStringStartsWith('http://localhost', $direct->get('/')->headers->get('Location'));
        Route::get('/_client-ip', fn (Request $request): string => (string) $request->ip());
        $direct->get('/_client-ip')->assertSeeText('203.0.113.5');
    }

    public function test_an_https_public_url_forces_https_links(): void
    {
        config(['app.url' => 'https://ipam.example.com']);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->assertStringStartsWith('https://', URL::to('/build/app.css'));
        URL::forceScheme(null);
    }
}
