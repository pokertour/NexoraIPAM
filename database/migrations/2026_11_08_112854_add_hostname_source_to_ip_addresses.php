<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->string('hostname_source', 16)->nullable();
        });

        DB::table('ip_addresses')->whereNotNull('resolved_hostname')->update(['hostname_source' => 'dns']);
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropColumn('hostname_source');
        });
    }
};
