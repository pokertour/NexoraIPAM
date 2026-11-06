<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->string('gateway', 45)->nullable();
            $table->string('dns_servers')->nullable();
            $table->string('domain', 253)->nullable();
            $table->string('dhcp_start', 45)->nullable();
            $table->string('dhcp_end', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subnets', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'dns_servers', 'domain', 'dhcp_start', 'dhcp_end']);
        });
    }
};
