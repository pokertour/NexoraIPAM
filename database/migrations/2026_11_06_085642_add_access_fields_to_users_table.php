<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer');
            $table->string('provider')->default('local');
            $table->string('external_id', 64)->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['role', 'provider', 'external_id', 'active', 'must_change_password']);
        });
    }
};
