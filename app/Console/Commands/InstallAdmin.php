<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

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

        $password = $this->temporaryPassword();
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

        // Raw output: the console would read <…> and \ in the password as formatting and print another one.
        $this->output->writeln('Identifiant : '.$user->email, OutputInterface::OUTPUT_RAW);
        $this->output->writeln('Mot de passe temporaire : '.$password, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** Letters, digits and symbols: any of them can be printed, see handle(). */
    protected function temporaryPassword(): string
    {
        return Str::password(28);
    }
}
