<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How ports are numbered on the front panel; empty follows the vendor. */
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('port_layout', 12)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn('port_layout');
        });
    }
};
