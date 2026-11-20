<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_donors', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('normalized_name')->unique();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('donation_drives', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('donation_drive_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_id')->constrained('donation_drives')->cascadeOnDelete();
            $table->string('original_name');
            $table->text('path');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('donation_drive_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_id')->constrained('donation_drives')->cascadeOnDelete();
            $table->foreignId('donor_id')->constrained('donation_donors')->cascadeOnDelete();
            $table->unsignedTinyInteger('cutoff');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['drive_id', 'donor_id', 'cutoff']);
        });

        Schema::create('donation_drive_donation_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained('donation_drive_donations')->cascadeOnDelete();
            $table->decimal('previous_amount', 12, 2)->nullable();
            $table->decimal('new_amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('donation_drive_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_id')->constrained('donation_drives')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->boolean('previous_is_active')->nullable();
            $table->boolean('current_is_active');
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('donation_drive_report_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_id')->constrained('donation_drives')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['drive_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_drive_report_users');
        Schema::dropIfExists('donation_drive_histories');
        Schema::dropIfExists('donation_drive_donation_histories');
        Schema::dropIfExists('donation_drive_donations');
        Schema::dropIfExists('donation_drive_documents');
        Schema::dropIfExists('donation_drives');
        Schema::dropIfExists('donation_donors');
    }
};
