<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::create('mobile_loa_request_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_request_id')->unique();
            $table->string('member_id', 15)->index();
            $table->json('details');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_loa_request_details');
    }
};
