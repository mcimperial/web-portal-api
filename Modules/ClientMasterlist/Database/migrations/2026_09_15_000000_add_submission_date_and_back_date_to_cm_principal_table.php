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
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->dateTime('submission_date')->nullable()->after('employment_end_date');
            $table->dateTime('back_date')->nullable()->after('submission_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->dropColumn(['submission_date', 'back_date']);
        });
    }
};
