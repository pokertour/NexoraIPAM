<?php

namespace App\Providers;

use App\Http\Middleware\WorkspaceAccess;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('edit-inventory', fn (User $user): bool => in_array($user->role, ['admin', 'operator'], true));
        Gate::define('administer', fn (User $user): bool => $user->role === 'admin');

        Livewire::addPersistentMiddleware([WorkspaceAccess::class]);

        $this->app->resolving('mail.manager', function (MailManager $manager): void {
            $smtp = Setting::read('smtp');
            if ($smtp['enabled'] ?? false) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp' => [
                        'transport' => 'smtp', 'scheme' => $smtp['scheme'], 'host' => $smtp['host'],
                        'port' => $smtp['port'], 'username' => $smtp['username'] ?? null, 'password' => $smtp['password'] ?? null,
                        'timeout' => 8, 'require_tls' => true,
                    ],
                    'mail.from' => ['address' => $smtp['from'], 'name' => config('app.name')],
                ]);
            }
        });
    }
}
