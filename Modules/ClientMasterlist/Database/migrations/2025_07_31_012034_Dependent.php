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
        Schema::create('cm_dependent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('principal_id')->nullable()->constrained('cm_principal')->onDelete('cascade');
            $table->string('member_id')->nullable();
            $table->string('employee_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('relation'); // e.g., spouse, child
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('enrollment_status')->nullable();
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
        Schema::dropIfExists('cm_dependent');
    }
};

#php artisan migrate:refresh --path=Modules/ClientMasterlist/Database/migrations/2025_07_31_012034_Dependent.php