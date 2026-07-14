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
        Schema::create('cm_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('tm_enrollment')->onDelete('cascade');
            $table->timestamp('import_date')->useCurrent();
            $table->integer('total_principals')->default(0);
            $table->integer('total_dependents')->default(0);
            $table->integer('principals_created')->default(0);
            $table->integer('principals_updated')->default(0);
            $table->integer('dependents_created')->default(0);
            $table->integer('dependents_updated')->default(0);
            $table->json('import_details')->nullable();
            $table->string('date_format_detected')->nullable();
            $table->string('date_format_confidence')->nullable();
            $table->enum('status', ['success', 'failed', 'partial'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('enrollment_id');
            $table->index('import_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_import_logs');
    }
};
