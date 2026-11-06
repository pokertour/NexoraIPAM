<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_address_id')->constrained()->cascadeOnDelete();
            $table->string('mac_address', 17)->nullable()->index();
            $table->string('hostname', 253)->nullable();
            $table->string('source', 16);
            $table->string('location')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('times_seen')->default(1);
            $table->index(['ip_address_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_observations');
    }
};
