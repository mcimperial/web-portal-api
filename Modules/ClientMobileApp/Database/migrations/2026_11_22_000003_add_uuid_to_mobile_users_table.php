<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->table('mobile_users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        DB::connection('mysql')->table('mobile_users')
            ->whereNull('uuid')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $user) {
                    DB::connection('mysql')->table('mobile_users')
                        ->where('id', $user->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        DB::connection('mysql')->statement(
            'ALTER TABLE `mobile_users` MODIFY `uuid` CHAR(36) NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('mobile_users', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
