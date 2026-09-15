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
        Schema::create('cm_principal_unmapped_columns', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('principal_id');
            $table->foreign('principal_id')
                ->references('id')->on('cm_principal')
                ->cascadeOnDelete();

            // Original (unmapped) Excel/CSV column header, e.g. "Column A".
            $table->string('column_name');

            // Raw value for that column/row from the imported file.
            $table->text('column_value')->nullable();

            $table->timestamps();

            $table->index(['principal_id', 'column_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_principal_unmapped_columns');
    }
};
