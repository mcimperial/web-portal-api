<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::create('mobile_loa_request_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->string('member_id', 15);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('portal_request_id')->nullable()->unique();
            $table->string('status', 20)->default('processing');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_loa_request_submissions');
    }
};
