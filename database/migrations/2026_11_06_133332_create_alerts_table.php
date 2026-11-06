<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('severity', 16)->default('warning');
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label');
            $table->json('details')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'subject_type', 'subject_id', 'resolved_at']);
            $table->index(['resolved_at', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
