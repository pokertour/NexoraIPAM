<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('key', 64);
            $table->string('applies_to', 16);
            $table->string('type', 16)->default('text');
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['applies_to', 'key']);
        });

        foreach (['subnets', 'ip_addresses', 'vlans'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('custom_values')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['subnets', 'ip_addresses', 'vlans'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('custom_values');
            });
        }

        Schema::dropIfExists('custom_fields');
    }
};
