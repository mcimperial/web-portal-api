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
        Schema::create('cm_notification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('cm_enrollment')->onDelete('cascade');
            $table->string('notification_type');
            $table->string('to')->nullable();
            $table->string('cc')->nullable();
            $table->string('title');
            $table->string('subject');
            $table->text('message'); // Store HTML email content
            $table->boolean('is_html')->default(false); // Indicates if message contains HTML
            $table->boolean('is_read')->default(false);
            $table->string('schedule')->nullable(); // Scheduler info if applicable
            $table->timestamp('last_sent_at')->nullable();
            $table->softDeletes();
            $table->string('deleted_by')->nullable(); // User who deleted the record
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_notification');
    }
};
