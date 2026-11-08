<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_ports', function (Blueprint $table) {
            $table->string('media', 8)->nullable();
            $table->string('mac_address', 17)->nullable()->index();
            $table->json('untagged_vlans')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_ports', function (Blueprint $table) {
            $table->dropIndex(['mac_address']);
            $table->dropColumn(['media', 'mac_address', 'untagged_vlans']);
        });
    }
};
