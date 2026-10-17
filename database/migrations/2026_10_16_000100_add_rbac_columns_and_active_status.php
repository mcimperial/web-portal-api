<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('password')->index();
            });
        }

        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                if (!Schema::hasColumn('permissions', 'sub_app')) {
                    $table->string('sub_app')->nullable()->after('app');
                }
                if (!Schema::hasColumn('permissions', 'app')) {
                    $table->string('app')->nullable()->after('id');
                }
                if (!Schema::hasColumn('permissions', 'is_system')) {
                    $table->boolean('is_system')->default(false)->after('description');
                }
            });

            Schema::table('permissions', function (Blueprint $table) {
                $table->index(['app', 'sub_app']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->dropIndex(['app', 'sub_app']);
                if (Schema::hasColumn('permissions', 'is_system')) {
                    $table->dropColumn('is_system');
                }
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
