<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedSmallInteger('discovery_interval')->nullable();
            $table->timestamp('next_discovery_at')->nullable()->index();
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->string('mac_address', 17)->nullable();
            $table->boolean('last_probe_responded')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['next_discovery_at']);
            $table->dropColumn(['discovery_interval', 'next_discovery_at']);
        });

        Schema::table('ip_addresses', fn (Blueprint $table) => $table->dropColumn(['mac_address', 'last_probe_responded']));
    }
};
