<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free text describing where a switch port is patched when it does not
     * reach a wall outlet: a printer, an access point, another rack…
     */
    public function up(): void
    {
        Schema::create('port_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_device_id')->constrained()->cascadeOnDelete();
            $table->string('port', 100);
            $table->string('label', 100);
            $table->timestamps();
            $table->unique(['network_device_id', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_destinations');
    }
};
