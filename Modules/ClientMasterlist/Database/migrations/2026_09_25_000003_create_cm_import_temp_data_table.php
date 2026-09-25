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
        Schema::create('cm_import_temp_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->date('import_date');
            $table->unsignedInteger('row_number');
            $table->json('column_names')->nullable();
            $table->json('row_data');
            $table->timestamps();

            $table->foreign('enrollment_id')
                ->references('id')
                ->on('cm_enrollment')
                ->cascadeOnDelete();

            $table->index(['enrollment_id', 'import_date']);
            $table->index(['enrollment_id', 'row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_import_temp_data');
    }
};
