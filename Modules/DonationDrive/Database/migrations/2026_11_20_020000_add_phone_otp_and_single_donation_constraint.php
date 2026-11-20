<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donation_donors', 'phone')) {
            Schema::table('donation_donors', function (Blueprint $table) {
                $table->string('phone', 20)->nullable()->unique()->after('normalized_name');
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            });
        }

        DB::table('donation_drive_donations')
            ->select('drive_id', 'donor_id')
            ->groupBy('drive_id', 'donor_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('drive_id')
            ->orderBy('donor_id')
            ->get()
            ->each(function ($group) {
                $ids = DB::table('donation_drive_donations')
                    ->where('drive_id', $group->drive_id)
                    ->where('donor_id', $group->donor_id)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->pluck('id');

                $ids->skip(1)->each(
                    fn ($id) => DB::table('donation_drive_donations')->where('id', $id)->delete()
                );
            });

        Schema::table('donation_drive_donations', function (Blueprint $table) {
            $table->dropForeign(['drive_id']);
            $table->dropForeign(['donor_id']);
            $table->dropUnique(['drive_id', 'donor_id', 'cutoff']);
            $table->unique(['drive_id', 'donor_id']);
            $table->foreign('drive_id')->references('id')->on('donation_drives')->cascadeOnDelete();
            $table->foreign('donor_id')->references('id')->on('donation_donors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donation_drive_donations', function (Blueprint $table) {
            $table->dropForeign(['drive_id']);
            $table->dropForeign(['donor_id']);
            $table->dropUnique(['drive_id', 'donor_id']);
            $table->unique(['drive_id', 'donor_id', 'cutoff']);
            $table->foreign('drive_id')->references('id')->on('donation_drives')->cascadeOnDelete();
            $table->foreign('donor_id')->references('id')->on('donation_donors')->cascadeOnDelete();
        });

        Schema::table('donation_donors', function (Blueprint $table) {
            $table->dropColumn(['phone', 'phone_verified_at']);
        });
    }
};
