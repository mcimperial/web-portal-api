<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_drive_documents', function (Blueprint $table) {
            $table->string('storage_disk', 32)->default('local');
        });
    }

    public function down(): void
    {
        Schema::table('donation_drive_documents', function (Blueprint $table) {
            $table->dropColumn('storage_disk');
        });
    }
};
