<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::table('mobile_users', function (Blueprint $table) {
            $table->string('contact_number', 11)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_users', function (Blueprint $table) {
            $table->dropColumn('contact_number');
        });
    }
};
