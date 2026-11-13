<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SNMP tuning for devices reached through a VPN or a slow link, and the
     * count of failed polls in a row, so one lost poll does not raise an alert.
     */
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->unsignedTinyInteger('snmp_timeout')->nullable()->after('snmp_version');
            $table->unsignedTinyInteger('snmp_retries')->nullable()->after('snmp_timeout');
            $table->unsignedTinyInteger('snmp_bulk')->nullable()->after('snmp_retries');
            $table->unsignedSmallInteger('poll_failures')->default(0)->after('poll_error');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn(['snmp_timeout', 'snmp_retries', 'snmp_bulk', 'poll_failures']);
        });
    }
};
