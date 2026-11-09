<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('role', 20)->default('other')->after('name');
        });
        Schema::table('device_ports', function (Blueprint $table) {
            $table->boolean('wan')->default(false)->after('media_override');
            $table->string('wan_label', 100)->nullable()->after('wan');
            $table->string('wan_address', 45)->nullable()->after('wan_label');
            $table->string('wan_reference', 100)->nullable()->after('wan_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_ports', function (Blueprint $table) {
            $table->dropColumn(['wan', 'wan_label', 'wan_address', 'wan_reference']);
        });
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
