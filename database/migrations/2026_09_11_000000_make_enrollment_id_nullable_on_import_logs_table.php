<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cm_import_logs', function (Blueprint $table) {
            // Drop the existing foreign key so we can alter the column to be nullable
            $table->dropForeign(['enrollment_id']);
        });

        Schema::table('cm_import_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('enrollment_id')->nullable()->change();
        });

        Schema::table('cm_import_logs', function (Blueprint $table) {
            $table->foreign('enrollment_id')
                ->references('id')->on('cm_enrollment')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cm_import_logs', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
        });

        Schema::table('cm_import_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('enrollment_id')->nullable(false)->change();
        });

        Schema::table('cm_import_logs', function (Blueprint $table) {
            $table->foreign('enrollment_id')
                ->references('id')->on('cm_enrollment')
                ->onDelete('cascade');
        });
    }
};
