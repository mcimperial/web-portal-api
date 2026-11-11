<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            if (!Schema::hasColumn('cm_enrollment', 'with_awaiting_deletion_approval')) {
                $table->boolean('with_awaiting_deletion_approval')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            if (Schema::hasColumn('cm_enrollment', 'with_awaiting_deletion_approval')) {
                $table->dropColumn('with_awaiting_deletion_approval');
            }
        });
    }
};
