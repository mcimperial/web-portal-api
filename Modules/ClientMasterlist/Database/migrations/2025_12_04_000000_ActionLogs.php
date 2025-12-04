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
        Schema::create('cm_action_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action_type'); // CREATE, READ, UPDATE, DELETE
            $table->string('model_type')->nullable(); // Model class name (e.g., Principal, Enrollment, etc.)
            $table->unsignedBigInteger('model_id')->nullable(); // ID of the model being acted upon
            $table->unsignedBigInteger('user_id')->nullable(); // User who performed the action
            $table->string('user_email')->nullable(); // Email of the user
            $table->string('user_name')->nullable(); // Name of the user
            $table->text('description')->nullable(); // Human-readable description of the action
            $table->json('old_values')->nullable(); // Previous values before update/delete
            $table->json('new_values')->nullable(); // New values after create/update
            $table->json('metadata')->nullable(); // Additional contextual information
            $table->string('ip_address')->nullable(); // IP address of the requester
            $table->string('user_agent')->nullable(); // Browser/client user agent
            $table->string('status')->default('success'); // success, failed, pending, etc.
            $table->text('error_message')->nullable(); // Error details if status is failed
            $table->timestamps();
            
            // Indexes for better query performance
            $table->index('action_type');
            $table->index('model_type');
            $table->index('model_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
            $table->index(['model_type', 'model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_action_logs');
    }
};
