<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cm_billing_records', function (Blueprint $table) {
            $table->string('billing_type')->default('credit_memo')->after('enrollment_id');
            $table->unsignedTinyInteger('billing_month')->nullable()->after('billing_type');
            $table->unsignedSmallInteger('billing_year')->nullable()->after('billing_month');

            $table->index('billing_type');
            $table->index(['billing_type', 'billing_year', 'billing_month'], 'cm_billing_type_year_month_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cm_billing_records', function (Blueprint $table) {
            $table->dropIndex('cm_billing_type_year_month_idx');
            $table->dropIndex(['billing_type']);
            $table->dropColumn(['billing_type', 'billing_month', 'billing_year']);
        });
    }
};
