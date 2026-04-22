<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ct_api_credentials', function (Blueprint $table) {
            // Change from TIMESTAMP (max 2038-01-19) to DATETIME (max 9999-12-31)
            $table->dateTime('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ct_api_credentials', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->change();
        });
    }
};
