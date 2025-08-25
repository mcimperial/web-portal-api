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
        Schema::create('cm_policy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('principal_id')->nullable()->constrained('cm_principal')->onDelete('cascade');
            $table->foreignId('dependent_id')->nullable()->constrained('cm_dependent')->onDelete('cascade');
            $table->string('provider');
            $table->string('policy_number')->unique();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('coverage_type'); // e.g., full, partial
            $table->decimal('premium_amount', 10, 2)->nullable();
            $table->string('status')->default('active'); // e.g., active, inactive
            $table->string('notes')->nullable();
            $table->string('version')->default('1.0'); // Versioning for the record
            $table->string('external_reference')->nullable(); // Reference to external systems if applicable
            $table->string('approval_status')->default('PENDING'); // e.g., pending, approved, rejected
            $table->string('approval_notes')->nullable(); // Notes related to approval
            $table->string('assigned_to')->nullable(); // User assigned to manage this record
            $table->string('priority')->default('NORMAL'); // e.g., low, normal
            $table->string('created_by')->nullable(); // User who created the record
            $table->string('updated_by')->nullable(); // User who last updated the record
            $table->softDeletes(); // For soft delete functionality
            $table->string('deleted_by')->nullable(); // User who deleted the record
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_healthcare');
    }
};
