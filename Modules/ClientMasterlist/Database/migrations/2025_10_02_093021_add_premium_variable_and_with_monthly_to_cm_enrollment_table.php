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
            $table->string('premium_variable')->nullable()->after('premium_computation');
            $table->boolean('with_monthly')->default(false)->after('premium_variable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            $table->dropColumn(['premium_variable', 'with_monthly']);
        });
    }
};
