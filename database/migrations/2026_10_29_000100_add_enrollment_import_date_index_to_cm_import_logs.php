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
        Schema::table('cm_import_logs', function (Blueprint $table) {
            $table->index(['enrollment_id', 'import_date'], 'cm_import_logs_enrollment_import_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_import_logs', function (Blueprint $table) {
            $table->dropIndex('cm_import_logs_enrollment_import_date_idx');
        });
    }
};
