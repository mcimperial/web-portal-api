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
            $table->string('age_restriction')->nullable()->after('with_monthly');
            $table->string('hierarchy_options')->nullable()->after('age_restriction');
            $table->string('premium_restriction')->nullable()->after('hierarchy_options');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            $table->dropColumn(['age_restriction', 'hierarchy_options', 'premium_restriction']);
        });
    }
};
