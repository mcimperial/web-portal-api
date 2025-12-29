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
            $table->integer('max_dependents')->nullable()->after('with_dependents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->dropColumn('max_dependents');
        });
    }
};
