<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The installation account used to be found by its e-mail address, so
     * renaming it made the next start create a second one. It is now known by
     * its id: the first local administrator, created at installation.
     */
    public function up(): void
    {
        if (isset(Setting::read('installation')['user_id'])) {
            return;
        }

        $id = User::where('provider', 'local')->where('role', 'admin')->orderBy('id')->value('id');
        if ($id !== null) {
            Setting::write('installation', ['user_id' => $id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::where('key', 'installation')->delete();
    }
};
