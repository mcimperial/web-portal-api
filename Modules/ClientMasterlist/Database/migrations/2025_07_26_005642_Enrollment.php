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
        Schema::create('cm_enrollment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('company')->onDelete('cascade');
            $table->foreignId('insurance_provider_id')->nullable()->constrained('cm_insurance_provider')->onDelete('cascade');
            $table->string('title')->nullable();
            $table->string('note')->nullable();

            $table->boolean('with_address')->default(true);
            $table->boolean('with_skip_hierarchy')->default(false);

            $table->float('principal_mbl')->nullable();
            $table->string('principal_room_and_board')->nullable();
            $table->float('dependent_mbl')->nullable();
            $table->string('dependent_room_and_board')->nullable();

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
        Schema::dropIfExists('cm_enrollment');
    }
};
