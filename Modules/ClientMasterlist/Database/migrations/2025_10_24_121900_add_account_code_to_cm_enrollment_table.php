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
            $table->string('account_code')->nullable()->after('with_skip_hierarchy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            $table->dropColumn(['account_code']);
        });
    }
};
