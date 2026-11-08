<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('racks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('units')->default(42);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('rack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_device_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->default('device');
            $table->string('label', 100)->nullable();
            $table->unsignedSmallInteger('position');
            $table->unsignedTinyInteger('height')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rack_items');
        Schema::dropIfExists('racks');
    }
};
