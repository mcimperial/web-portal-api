<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('mobile_complaints', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->string('normalized_title', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('mobile_complaints');
    }
};
