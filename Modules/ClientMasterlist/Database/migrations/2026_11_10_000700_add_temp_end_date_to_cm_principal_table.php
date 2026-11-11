<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->date('temp_end_date')->nullable()->after('employment_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('cm_principal', function (Blueprint $table) {
            $table->dropColumn('temp_end_date');
        });
    }
};
