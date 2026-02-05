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
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_otp', 6)->nullable()->after('email');
            $table->timestamp('email_otp_expires_at')->nullable()->after('email_otp');
            $table->integer('email_otp_attempts')->default(0)->after('email_otp_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_otp', 'email_otp_expires_at', 'email_otp_attempts']);
        });
    }
};
