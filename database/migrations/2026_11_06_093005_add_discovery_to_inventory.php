<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->uuid('scan_token')->nullable();
            $table->string('scan_status', 16)->nullable();
            $table->timestamp('scan_requested_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->unsignedSmallInteger('scan_targets')->nullable();
            $table->unsignedSmallInteger('scan_responded')->nullable();
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->string('resolved_hostname', 253)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_probe_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', fn (Blueprint $table) => $table->dropColumn(['resolved_hostname', 'last_seen_at', 'last_probe_at']));
        Schema::table('subnets', fn (Blueprint $table) => $table->dropColumn([
            'scan_token', 'scan_status', 'scan_requested_at', 'scanned_at', 'scan_targets', 'scan_responded',
        ]));
    }
};
