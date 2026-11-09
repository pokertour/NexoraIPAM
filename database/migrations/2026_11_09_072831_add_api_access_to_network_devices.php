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
        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('api_driver', 40)->nullable()->after('credentials');
            $table->unsignedInteger('api_port')->nullable()->after('api_driver');
            $table->boolean('api_verify_tls')->default(true)->after('api_port');
            $table->text('api_credentials')->nullable()->after('api_verify_tls');
            $table->string('api_error')->nullable()->after('poll_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn(['api_driver', 'api_port', 'api_verify_tls', 'api_credentials', 'api_error']);
        });
    }
};
