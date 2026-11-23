<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->table('registration_otps', function (Blueprint $table) {
            $table->string('reset_token_hash', 64)->nullable()->after('verified');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('registration_otps', function (Blueprint $table) {
            $table->dropColumn('reset_token_hash');
        });
    }
};
