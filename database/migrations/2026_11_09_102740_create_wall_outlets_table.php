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
        Schema::create('wall_outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 50);
            $table->foreignId('network_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('port', 100)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'name']);
            $table->unique(['network_device_id', 'port']);
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wall_outlets');
    }
};
