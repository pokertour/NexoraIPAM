<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('host', 253);
            $table->unsignedInteger('port')->default(161);
            $table->string('snmp_version', 4)->default('2c');
            $table->text('credentials')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->unsignedInteger('poll_interval')->nullable();
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->uuid('poll_token')->nullable();
            $table->string('poll_status', 16)->nullable();
            $table->string('poll_error')->nullable();
            $table->timestamp('poll_requested_at')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->string('sys_name')->nullable();
            $table->text('sys_descr')->nullable();
            $table->string('sys_object_id')->nullable();
            $table->unsignedBigInteger('sys_uptime')->nullable();
            $table->unsignedInteger('arp_entries')->nullable();
            $table->timestamps();
        });

        Schema::create('device_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('if_index');
            $table->unsignedInteger('bridge_port')->nullable();
            $table->string('name')->nullable();
            $table->string('descr')->nullable();
            $table->string('alias')->nullable();
            $table->unsignedInteger('type')->nullable();
            $table->boolean('physical')->default(false);
            $table->unsignedTinyInteger('admin_status')->nullable();
            $table->unsignedTinyInteger('oper_status')->nullable();
            $table->unsignedInteger('speed_mbps')->nullable();
            $table->unsignedSmallInteger('pvid')->nullable();
            $table->json('tagged_vlans')->nullable();
            $table->string('in_octets', 24)->nullable();
            $table->string('out_octets', 24)->nullable();
            $table->unsignedBigInteger('in_bps')->nullable();
            $table->unsignedBigInteger('out_bps')->nullable();
            $table->unsignedBigInteger('in_errors')->nullable();
            $table->unsignedBigInteger('out_errors')->nullable();
            $table->unsignedInteger('mac_count')->default(0);
            $table->string('neighbor_name')->nullable();
            $table->string('neighbor_port')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->unique(['network_device_id', 'if_index']);
        });

        Schema::create('device_port_macs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_port_id')->constrained()->cascadeOnDelete();
            $table->string('mac_address', 17)->index();
            $table->unsignedSmallInteger('vlan')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_port_macs');
        Schema::dropIfExists('device_ports');
        Schema::dropIfExists('network_devices');
    }
};
