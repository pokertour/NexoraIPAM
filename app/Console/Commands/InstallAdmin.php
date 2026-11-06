<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallAdmin extends Command
{
    public const EMAIL = 'admin@example.com';

    protected $signature = 'nexora:install';

    protected $description = 'Crée le compte administrateur initial et affiche son mot de passe temporaire';

    public function handle(): int
    {
        if (! config('app.key')) {
            $this->error('Générez la clé applicative avec php artisan key:generate.');

            return self::FAILURE;
        }

        $password = Str::password(28);
        $user = User::firstOrCreate(['email' => self::EMAIL], [
            'name' => 'Administration',
            'password' => $password,
            'role' => 'admin',
            'provider' => 'local',
            'must_change_password' => true,
        ]);

        if (! $user->wasRecentlyCreated) {
            $this->warn('Le compte initial existe déjà.');

            return self::SUCCESS;
        }

        $this->line('Identifiant : '.$user->email);
        $this->line('Mot de passe temporaire : '.$password);

        return self::SUCCESS;
    }
}
