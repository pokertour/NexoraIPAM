<?php

namespace Tests\Feature;

use App\Jobs\DiscoverSubnet;
use App\Jobs\ImportDhcpLeases;
use App\Jobs\PollNetworkDevice;
use App\Livewire\ScanHistory;
use App\Models\NetworkDevice;
use App\Models\ScanRun;
use App\Models\Setting;
use App\Models\Subnet;
use App\Models\User;
use App\Support\DevicePollQueue;
use App\Support\DiscoveryQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ScanHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manual_discovery_is_tracked_from_the_queue_to_its_result(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $subnet = Subnet::factory()->create(['name' => 'Lab', 'cidr' => '192.0.2.0/30']);

        $this->assertNull(app(DiscoveryQueue::class)->enqueue($subnet->id));

        $run = ScanRun::sole();
        $this->assertSame(['discovery', 'subnet', $subnet->id, 'Lab · 192.0.2.0/30', 'manual', $admin->id, 'queued'], [
            $run->kind, $run->subject_type, $run->subject_id, $run->subject_label, $run->trigger, $run->user_id, $run->status,
        ]);
        $job = Queue::pushed(DiscoverSubnet::class)->sole();
        $this->assertSame($run->id, $job->runId);

        Process::fake([
            "*'ping'*'192.0.2.1'" => Process::result(),
            "*'ping'*" => Process::result(exitCode: 1),
            "*'getent'*" => Process::result(exitCode: 2),
            "*'ip'*'neigh'*" => Process::result(output: '[]'),
        ]);
        app()->call([$job, 'handle']);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(['targets' => 2, 'responded' => 1], $run->summary);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    public function test_scheduled_runs_have_no_author_and_failures_keep_their_reason(): void
    {
        Queue::fake();
        $device = NetworkDevice::factory()->create(['name' => 'core-sw']);

        $this->assertNull(app(DevicePollQueue::class)->enqueue($device->id));
        $run = ScanRun::sole();
        $this->assertSame(['snmp', 'scheduled', null], [$run->kind, $run->trigger, $run->user_id]);

        Queue::pushed(PollNetworkDevice::class)->sole()->failed(new RuntimeException('Timeout'));
        $this->assertSame(['failed', 'L’interrogation a échoué ou dépassé le délai.'], [$run->fresh()->status, $run->fresh()->error]);

        ScanRun::finish($run->id, 'completed', ['ports' => 4]);
        $this->assertSame('failed', $run->fresh()->status, 'A finished run is never overwritten.');
    }

    public function test_dhcp_imports_and_superseded_jobs_are_recorded(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(status: 500)]);
        Setting::write('dhcp', ['enabled' => true, 'kea_url' => 'https://kea.example.test:8000/', 'interval' => 15]);

        $this->artisan('nexora:import-dhcp')->assertSuccessful();
        $run = ScanRun::sole();
        $this->assertSame(['dhcp', 'DHCP', 'scheduled', 'queued'], [$run->kind, $run->subject_label, $run->trigger, $run->status]);

        app()->call([Queue::pushed(ImportDhcpLeases::class)->sole(), 'handle']);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('Kea', $run->fresh()->error);

        $subnet = Subnet::factory()->create(['cidr' => '192.0.2.0/30']);
        $stale = ScanRun::queue('discovery', $subnet);
        app()->call([new DiscoverSubnet($subnet->id, 'outdated-token', $stale->id), 'handle']);
        $this->assertSame('cancelled', $stale->fresh()->status);
    }

    public function test_every_user_can_browse_and_filter_the_history(): void
    {
        $this->withoutVite();
        $reader = User::factory()->create(['role' => 'viewer']);
        $subnet = Subnet::factory()->create(['cidr' => '198.51.100.0/24']);
        ScanRun::factory()->create(['subject_type' => 'subnet', 'subject_id' => $subnet->id, 'subject_label' => '198.51.100.0/24', 'trigger' => 'manual', 'user_id' => $reader->id]);
        ScanRun::factory()->failed('SNMP sans réponse')->create(['kind' => 'snmp', 'subject_label' => 'edge-sw']);

        $this->actingAs($reader)->get(route('scans'))->assertOk()->assertSee('Historique des scans');

        Livewire::test(ScanHistory::class)
            ->assertSee('198.51.100.0/24')->assertSee('edge-sw')->assertSee('SNMP sans réponse')
            ->assertSeeHtml('href="'.route('addresses', ['subnet' => $subnet->id]).'"')
            ->assertSee($reader->name)->assertSee('Adresses sondées')
            ->set('kind', 'snmp')->assertSee('edge-sw')->assertDontSee('198.51.100.0/24')
            ->set('kind', '')->set('status', 'completed')->assertSee('198.51.100.0/24')->assertDontSee('edge-sw')
            ->set('status', '')->set('search', 'edge')->assertSee('edge-sw')->assertDontSee('198.51.100.0/24');
    }

    public function test_old_finished_runs_are_pruned(): void
    {
        $old = ScanRun::factory()->create(['created_at' => now()->subDays(ScanRun::RETENTION_DAYS + 1)]);
        $stuck = ScanRun::factory()->create(['status' => 'running', 'created_at' => now()->subDays(ScanRun::RETENTION_DAYS + 1)]);
        $recent = ScanRun::factory()->create();

        $this->artisan('model:prune', ['--model' => [ScanRun::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($stuck);
        $this->assertModelExists($recent);
    }
}
