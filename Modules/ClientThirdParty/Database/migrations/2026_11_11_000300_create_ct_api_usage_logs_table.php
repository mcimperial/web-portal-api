<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ct_api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_credential_id')
                ->nullable()
                ->constrained('ct_api_credentials')
                ->nullOnDelete();

            $table->string('api_key_last4', 8)->nullable();
            $table->string('process')->nullable();
            $table->string('route_name')->nullable();
            $table->string('method', 12);
            $table->string('endpoint', 512);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('status')->default('success');
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['api_credential_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ct_api_usage_logs');
    }
};
