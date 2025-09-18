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
        Schema::create('cm_insurance_provider', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('note')->nullable();
            $table->softDeletes(); // For soft delete functionality
            $table->string('deleted_by')->nullable(); // User who deleted the record
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_insurance_provider');
    }
};
