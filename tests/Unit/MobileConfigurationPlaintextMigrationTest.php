<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileConfigurationPlaintextMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('mobile_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('config_key')->unique();
            $table->text('api_key')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_converts_an_encrypted_key_without_modifying_other_settings(): void
    {
        DB::connection('mysql')->table('mobile_configuration')->insert([
            ['config_key' => 'loa_request_backend', 'api_key' => Crypt::encryptString('test-key')],
            ['config_key' => 'other_setting', 'api_key' => 'other-key'],
        ]);

        $this->migration()->up();

        $this->assertSame('test-key', DB::connection('mysql')->table('mobile_configuration')
            ->where('config_key', 'loa_request_backend')->value('api_key'));
        $this->assertSame('other-key', DB::connection('mysql')->table('mobile_configuration')
            ->where('config_key', 'other_setting')->value('api_key'));
    }

    public function test_it_leaves_an_existing_plaintext_key_unchanged(): void
    {
        DB::connection('mysql')->table('mobile_configuration')->insert([
            'config_key' => 'loa_request_backend',
            'api_key' => 'plaintext-test-key',
        ]);

        $this->migration()->up();

        $this->assertSame('plaintext-test-key', DB::connection('mysql')->table('mobile_configuration')
            ->where('config_key', 'loa_request_backend')->value('api_key'));
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_22_000006_store_mobile_api_key_as_plaintext.php');
    }
}
