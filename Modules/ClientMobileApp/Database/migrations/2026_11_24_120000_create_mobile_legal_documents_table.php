<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->create('mobile_legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_key')->unique();
            $table->string('title');
            $table->longText('html_content');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::connection('mysql')->table('mobile_legal_documents')->insert([
            [
                'document_key' => 'terms',
                'title' => 'Terms of Service',
                'html_content' => '<h2>Terms of Service</h2><p>By creating and using an LLIBI Mobile App account, you agree to provide accurate information, keep your login credentials secure, and use the service only for lawful purposes.</p><p>You are responsible for activity performed through your account. LLIBI may suspend access when required for security, fraud prevention, service protection, or legal compliance.</p><p>The app provides access to insurance-related information and services. Coverage, eligibility, claims, and benefit decisions remain subject to the applicable policy and provider terms.</p><p>Contact LLIBI if you need assistance or believe your account has been accessed without authorization.</p>',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'document_key' => 'privacy',
                'title' => 'Privacy Policy',
                'html_content' => '<h2>Privacy Policy</h2><p>LLIBI collects the account and transaction information needed to provide mobile app services, including your name, email address, optional contact number, authentication details, and service requests.</p><p>Information is used to verify your identity, deliver requested services, protect the account, communicate service updates, and meet legal or policy requirements.</p><p>LLIBI applies reasonable administrative, technical, and organizational safeguards. Information is shared only with authorized service providers and insurers when necessary to deliver requested services or comply with applicable requirements.</p><p>You may request correction or deletion of eligible personal information through the account settings or LLIBI support channels.</p>',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::connection('mysql')->table('mobile_users', function (Blueprint $table) {
            $table->unsignedInteger('terms_version')->nullable()->after('email_verified_at');
            $table->unsignedInteger('privacy_version')->nullable()->after('terms_version');
            $table->timestamp('legal_accepted_at')->nullable()->after('privacy_version');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('mobile_users', function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'privacy_version', 'legal_accepted_at']);
        });
        Schema::connection('mysql')->dropIfExists('mobile_legal_documents');
    }
};
