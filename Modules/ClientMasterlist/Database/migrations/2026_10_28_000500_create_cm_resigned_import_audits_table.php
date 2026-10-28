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
        Schema::create('cm_resigned_import_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('principal_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('import_log_id')->nullable();

            $table->string('employee_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('resigned_date');

            $table->string('source_file_original_name')->nullable();
            $table->string('source_file_stored_name')->nullable();
            $table->text('source_file_path')->nullable();
            $table->text('source_file_url')->nullable();
            $table->string('source_company_code')->nullable();
            $table->string('source_provider_title')->nullable();

            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->foreign('principal_id')->references('id')->on('cm_principal')->cascadeOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('cm_enrollment')->nullOnDelete();
            $table->foreign('import_log_id')->references('id')->on('cm_import_logs')->nullOnDelete();

            $table->unique('principal_id');
            $table->index('employee_id');
            $table->index('resigned_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_resigned_import_audits');
    }
};
