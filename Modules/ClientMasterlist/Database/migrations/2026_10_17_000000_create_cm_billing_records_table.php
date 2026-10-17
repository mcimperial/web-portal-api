<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cm_billing_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('cm_enrollment')->cascadeOnDelete();
            $table->unsignedBigInteger('principal_id')->nullable();
            $table->unsignedBigInteger('dependent_id')->nullable();
            $table->string('source_file_name')->nullable();
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('source_row_number');
            $table->json('column_names');
            $table->json('row_data');
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('enrollment_id');
            $table->index('principal_id');
            $table->index('dependent_id');
            $table->index('imported_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cm_billing_records');
    }
};