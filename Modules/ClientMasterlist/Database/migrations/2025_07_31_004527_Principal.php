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
        Schema::create('cm_principal', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('company')->onDelete('cascade');
            $table->string('member_id')->nullable();
            $table->string('employee_id')->unique();
            $table->string('department')->nullable();
            $table->string('position')->nullable();
            $table->date('employment_start_date')->nullable();
            $table->date('employment_end_date')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('middle_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->string('nationality')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('email');
            $table->string('phone1')->nullable();
            $table->string('phone2')->nullable();
            $table->string('address')->nullable();
            $table->string('notes')->nullable();
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
        Schema::dropIfExists('cm_principal');
    }
};
