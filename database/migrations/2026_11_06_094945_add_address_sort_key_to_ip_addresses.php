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
            $table->string('address_key', 32)->nullable();
            $table->index(['subnet_id', 'address_key']);
        });

        DB::table('ip_addresses')->select('id', 'address')->eachById(function (object $row): void {
            DB::table('ip_addresses')->where('id', $row->id)->update(['address_key' => bin2hex(inet_pton($row->address))]);
        });

        Schema::table('subnets', function (Blueprint $table) {
            $table->foreignId('parent_subnet_id')->nullable()->constrained('subnets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subnets', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_subnet_id'));
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropIndex(['subnet_id', 'address_key']);
            $table->dropColumn('address_key');
        });
    }
};
