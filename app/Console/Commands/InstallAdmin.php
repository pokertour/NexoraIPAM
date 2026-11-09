<?php

namespace App\Console\Commands;

use App\Models\Setting;
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

        // The installation account is known by its id: renaming its e-mail must not create a new one.
        $existing = self::account() ?? User::where('provider', 'local')->where('role', 'admin')->orderBy('id')->first();
        if ($existing) {
            Setting::write('installation', ['user_id' => $existing->id]);
            $this->warn('Le compte initial existe déjà.');

            return self::SUCCESS;
        }

        $password = $this->temporaryPassword();
        $user = User::create([
            'name' => 'Administration',
            'email' => self::EMAIL,
            'password' => $password,
            'role' => 'admin',
            'provider' => 'local',
            'must_change_password' => true,
        ]);
        Setting::write('installation', ['user_id' => $user->id]);

        // Raw output: the console would read <…> and \ in the password as formatting and print another one.
        $this->output->writeln('Identifiant : '.$user->email, OutputInterface::OUTPUT_RAW);
        $this->output->writeln('Mot de passe temporaire : '.$password, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** The installation account, which keeps local sign-in as a fallback and cannot be deleted. */
    public static function account(): ?User
    {
        $id = Setting::read('installation')['user_id'] ?? null;

        return is_int($id) ? User::find($id) : null;
    }

    public static function isInstallAccount(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        $id = Setting::read('installation')['user_id'] ?? null;

        // Before the id is recorded, the account still bears the e-mail it was created with.
        return is_int($id) ? $user->id === $id : $user->email === self::EMAIL;
    }

    /** Letters, digits and symbols: any of them can be printed, see handle(). */
    protected function temporaryPassword(): string
    {
        return Str::password(28);
    }
}
