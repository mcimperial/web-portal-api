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
        if (!Schema::hasColumn('cm_notification', 'bcc')) {
            Schema::table('cm_notification', function (Blueprint $table) {
                $table->string('bcc')->nullable()->after('cc');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('cm_notification', 'bcc')) {
            Schema::table('cm_notification', function (Blueprint $table) {
                $table->dropColumn('bcc');
            });
        }
    }
};
