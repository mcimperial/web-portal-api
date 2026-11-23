<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('mobile_callback_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mobile_user_id')->constrained('mobile_users')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('requestor_name');
            $table->string('email');
            $table->string('mobile', 11);
            $table->string('landline', 40)->nullable();
            $table->text('request_message');
            $table->string('alt_email')->nullable();
            $table->string('alt_landline', 40)->nullable();
            $table->string('alt_mobile', 11)->nullable();
            $table->string('status', 20)->default('processing');
            $table->timestamps();

            $table->unique(['mobile_user_id', 'idempotency_key']);
            $table->index(['mobile_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('mobile_callback_requests');
    }
};
