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
        Schema::create('cm_health_insurance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('principal_id')->nullable()->constrained('cm_principal')->onDelete('cascade');
            $table->foreignId('dependent_id')->nullable()->constrained('cm_dependent')->onDelete('cascade');
            $table->float('premium')->default(0);
            $table->string('plan')->nullable();
            $table->float('principal_mbl')->nullable();
            $table->string('principal_room_and_board')->nullable();
            $table->float('dependent_mbl')->nullable();
            $table->string('dependent_room_and_board')->nullable();
            $table->boolean('is_renewal')->default(false);
            $table->boolean('is_company_paid')->default(true);
            $table->string('coverage')->nullable();
            $table->date('coverage_start_date')->nullable();
            $table->date('coverage_end_date')->nullable();
            $table->string('certificate_number')->nullable();
            $table->date('certificate_date_issued')->nullable();
            $table->boolean('is_skipping')->default(false);
            $table->string('reason_for_skipping')->nullable();
            $table->string('attachment_for_skipping')->nullable();
            $table->boolean('is_kyc_approved')->default(false);
            $table->date('kyc_datestamp')->nullable();
            $table->boolean('is_card_delivered')->default(false);
            $table->date('card_delivery_date')->nullable();
            $table->string('notes')->nullable();
            $table->string('status')->default('ACTIVE'); // e.g., active, inactive
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cm_health_insurance');
    }
};
