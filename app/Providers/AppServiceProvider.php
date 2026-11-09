<?php

namespace App\Providers;

use App\Http\Middleware\WorkspaceAccess;
use App\Models\IpAddress;
use App\Models\NetworkDevice;
use App\Models\Setting;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // With an HTTPS public URL, links stay in HTTPS even if a proxy forgets X-Forwarded-Proto.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Gate::define('edit-inventory', fn (User $user): bool => in_array($user->role, ['admin', 'operator'], true));
        Gate::define('administer', fn (User $user): bool => $user->role === 'admin');

        Relation::morphMap([
            'subnet' => Subnet::class,
            'ip_address' => IpAddress::class,
            'vlan' => Vlan::class,
            'network_device' => NetworkDevice::class,
        ]);

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->currentAccessToken()?->id ?? $request->ip()));

        Livewire::addPersistentMiddleware([WorkspaceAccess::class]);

        $this->app->resolving('mail.manager', function (MailManager $manager): void {
            $smtp = Setting::read('smtp');
            if ($smtp['enabled'] ?? false) {
                // smtps: implicit TLS. smtp: STARTTLS, required. none: plain SMTP for a local relay, without STARTTLS.
                $plain = ($smtp['scheme'] ?? null) === 'none';
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp' => [
                        'transport' => 'smtp', 'scheme' => $plain ? 'smtp' : $smtp['scheme'], 'host' => $smtp['host'],
                        'port' => $smtp['port'], 'username' => $smtp['username'] ?? null, 'password' => $smtp['password'] ?? null,
                        'timeout' => 8, 'require_tls' => ! $plain, 'auto_tls' => ! $plain,
                    ],
                    'mail.from' => ['address' => $smtp['from'], 'name' => config('app.name')],
                ]);
            }
        });
    }
}
