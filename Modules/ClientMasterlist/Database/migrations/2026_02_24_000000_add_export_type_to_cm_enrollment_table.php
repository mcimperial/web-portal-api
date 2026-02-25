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
        Schema::table('cm_enrollment', function (Blueprint $table) {
            $table->string('export_type')->nullable()->after('plan_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            $table->dropColumn('export_type');
        });
    }
};
