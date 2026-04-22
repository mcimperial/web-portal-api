<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ct_api_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            // Identifying information
            $table->string('name');                        // Friendly name, e.g. "ACME HR System"
            $table->string('client_name')->nullable();     // Company / third-party client name
            $table->string('contact_email')->nullable();   // Contact person e-mail

            // Authentication
            $table->string('api_key', 64)->unique();       // Public key sent in X-API-Key header
            $table->string('api_secret', 128);             // Hashed secret (optional HMAC use)

            // Access control
            $table->json('permissions')->nullable();       // e.g. ["enrollment:read","enrollment:write"]
            $table->json('allowed_ips')->nullable();       // Optional IP whitelist
            $table->timestamp('expires_at')->nullable();   // Null = never expires

            // Usage tracking
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('request_count')->default(0);

            // Status
            $table->string('status')->default('ACTIVE');   // ACTIVE | INACTIVE | REVOKED
            $table->string('notes')->nullable();

            $table->softDeletes();
            $table->string('deleted_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ct_api_credentials');
    }
};
