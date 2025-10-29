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
        // Add suffix column to cm_principal table
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->string('suffix')->nullable()->after('middle_name');
        });

        // Add suffix column to cm_dependent table
        Schema::table('cm_dependent', function (Blueprint $table) {
            $table->string('suffix')->nullable()->after('middle_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove suffix column from cm_principal table
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->dropColumn('suffix');
        });

        // Remove suffix column from cm_dependent table
        Schema::table('cm_dependent', function (Blueprint $table) {
            $table->dropColumn('suffix');
        });
    }
};
