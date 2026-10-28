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
        Schema::create('cm_imported_enrollment_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('import_log_id')->nullable();
            $table->string('source_type')->default('regular');
            $table->string('company_code')->nullable();
            $table->string('provider_title')->nullable();
            $table->string('original_name')->nullable();
            $table->string('stored_name')->nullable();
            $table->text('file_path')->nullable();
            $table->text('file_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->foreign('enrollment_id')->references('id')->on('cm_enrollment')->nullOnDelete();
            $table->foreign('import_log_id')->references('id')->on('cm_import_logs')->nullOnDelete();

            $table->index(['company_code', 'provider_title', 'uploaded_at'], 'cm_imported_enrollment_files_company_provider_uploaded_idx');
            $table->index(['enrollment_id', 'uploaded_at'], 'cm_imported_enrollment_files_enrollment_uploaded_idx');
            $table->index('source_type');
            $table->index('uploaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_imported_enrollment_files');
    }
};
