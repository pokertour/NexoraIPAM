<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An end is now a rack item or, for devices that are not racked, the
     * device itself.
     */
    public function up(): void
    {
        Schema::table('cable_links', function (Blueprint $table) {
            $table->foreignId('a_rack_item_id')->nullable()->change();
            $table->foreignId('b_rack_item_id')->nullable()->change();
            $table->foreignId('a_network_device_id')->nullable()->after('a_rack_item_id')->constrained('network_devices')->cascadeOnDelete();
            $table->foreignId('b_network_device_id')->nullable()->after('b_rack_item_id')->constrained('network_devices')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cable_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('a_network_device_id');
            $table->dropConstrainedForeignId('b_network_device_id');
        });
    }
};
