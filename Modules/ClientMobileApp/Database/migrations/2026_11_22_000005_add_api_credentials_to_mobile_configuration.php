<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->table('mobile_configuration', function (Blueprint $table) {
            $table->string('api_header')->nullable();
            $table->text('api_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('mobile_configuration', function (Blueprint $table) {
            $table->dropColumn(['api_header', 'api_key']);
        });
    }
};
