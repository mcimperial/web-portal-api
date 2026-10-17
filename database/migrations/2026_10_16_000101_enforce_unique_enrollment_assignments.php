<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cm_enrollment_role')) {
            return;
        }

        $duplicateCount = DB::table('cm_enrollment_role')
            ->select('user_id', 'enrollment_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('user_id')
            ->whereNotNull('enrollment_id')
            ->groupBy('user_id', 'enrollment_id')
            ->having('total', '>', 1)
            ->count();

        if ($duplicateCount > 0) {
            Log::warning('Skipping unique constraint on cm_enrollment_role because duplicate user/enrollment assignments exist.', [
                'duplicates_found' => $duplicateCount,
            ]);

            Schema::table('cm_enrollment_role', function (Blueprint $table) {
                $table->index('user_id');
                $table->index('enrollment_id');
            });

            return;
        }

        Schema::table('cm_enrollment_role', function (Blueprint $table) {
            $table->unique(['user_id', 'enrollment_id'], 'cm_enrollment_role_user_enrollment_unique');
            $table->index('user_id');
            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cm_enrollment_role')) {
            return;
        }

        try {
            Schema::table('cm_enrollment_role', function (Blueprint $table) {
                $table->dropUnique('cm_enrollment_role_user_enrollment_unique');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('cm_enrollment_role', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('cm_enrollment_role', function (Blueprint $table) {
                $table->dropIndex(['enrollment_id']);
            });
        } catch (\Throwable $e) {
        }
    }
};
