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
        Schema::create('cm_enrollment_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_enrollment_id')->constrained('cm_enrollment')->onDelete('cascade');
            $table->foreignId('target_enrollment_id')->constrained('cm_enrollment')->onDelete('cascade');
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->string('status')->default('completed');
            $table->json('summary')->nullable();
            $table->json('skipped')->nullable();
            $table->json('rollback_payload')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->unsignedBigInteger('rolled_back_by')->nullable();
            $table->timestamps();

            $table->index(['source_enrollment_id', 'created_at']);
            $table->index(['target_enrollment_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_enrollment_sync_logs');
    }
};
