<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            if (!Schema::hasColumn('cm_enrollment', 'with_lowercase_output')) {
                $table->boolean('with_lowercase_output')->default(false)->after('with_optout');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cm_enrollment', function (Blueprint $table) {
            if (Schema::hasColumn('cm_enrollment', 'with_lowercase_output')) {
                $table->dropColumn('with_lowercase_output');
            }
        });
    }
};
