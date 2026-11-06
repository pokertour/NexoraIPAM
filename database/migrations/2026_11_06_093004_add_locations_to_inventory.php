<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['subnets' => 'cidr', 'vlans' => 'number'] as $name => $column) {
            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('scope_id')->default(0);
                $table->dropUnique([$column]);
                $table->unique(['scope_id', $column]);
            });
        }
    }

    public function down(): void
    {
        foreach (['subnets' => 'cidr', 'vlans' => 'number'] as $name => $column) {
            Schema::table($name, function (Blueprint $table) use ($column) {
                $table->unique([$column]);
                $table->dropUnique(['scope_id', $column]);
                $table->dropConstrainedForeignId('location_id');
                $table->dropColumn('scope_id');
            });
        }
    }
};
