<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->table('mobile_popup_messages', function (Blueprint $table) {
            $table->string('title')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('mobile_popup_messages', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
