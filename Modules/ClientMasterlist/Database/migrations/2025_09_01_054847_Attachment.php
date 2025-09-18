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
        Schema::create('cm_attachment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('principal_id')->nullable()->constrained('cm_principal')->onDelete('cascade');
            $table->foreignId('dependent_id')->nullable()->constrained('cm_dependent')->onDelete('cascade');
            $table->foreignId('notification_id')->nullable()->constrained('cm_notification')->onDelete('cascade');
            $table->string('attachment_for');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_type');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_attachment');
    }
};
