<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileConfigurationUrlMigrationTest extends TestCase
{
    public function test_it_backfills_the_existing_loa_row_without_changing_credentials(): void
    {
        config([
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'services.clientcare.base_url' => 'https://example.test/portal',
        ]);
        DB::purge('mysql');
        Schema::connection('mysql')->create('mobile_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('config_key')->unique();
            $table->string('config_value');
            $table->string('api_header')->nullable();
            $table->text('api_key')->nullable();
            $table->timestamps();
        });
        DB::connection('mysql')->table('mobile_configuration')->insert([
            ['config_key' => 'loa_request_backend', 'config_value' => 'third_party', 'api_header' => 'HEADER', 'api_key' => 'test-key'],
            ['config_key' => 'other_setting', 'config_value' => 'other', 'api_header' => null, 'api_key' => null],
        ]);

        $migration = require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_22_000007_add_api_url_to_mobile_configuration.php');
        $migration->up();

        $setting = DB::connection('mysql')->table('mobile_configuration')->where('config_key', 'loa_request_backend')->first();
        $this->assertSame('https://example.test/portal', $setting->api_url);
        $this->assertSame('third_party', $setting->config_value);
        $this->assertSame('HEADER', $setting->api_header);
        $this->assertSame('test-key', $setting->api_key);
        $this->assertNull(DB::connection('mysql')->table('mobile_configuration')->where('config_key', 'other_setting')->value('api_url'));
    }
}
