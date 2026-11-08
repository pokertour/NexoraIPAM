<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('api_token_id')->nullable()->index();
            $table->string('api_token_name', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['api_token_id']);
            $table->dropColumn(['api_token_id', 'api_token_name']);
        });
    }
};
