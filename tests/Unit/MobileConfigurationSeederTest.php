<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use Modules\ClientMobileApp\Database\Seeders\MobileConfigurationSeeder;
use Tests\TestCase;

class MobileConfigurationSeederTest extends TestCase
{
    private string|false $previousSeedKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSeedKey = getenv('CLIENTCARE_SEED_API_KEY');
        putenv('CLIENTCARE_SEED_API_KEY');
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
            $table->text('config_value');
            $table->string('api_header')->nullable();
            $table->text('api_key')->nullable();
            $table->string('api_url')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if ($this->previousSeedKey === false) {
            putenv('CLIENTCARE_SEED_API_KEY');
        } else {
            putenv('CLIENTCARE_SEED_API_KEY='.$this->previousSeedKey);
        }

        parent::tearDown();
    }

    public function test_it_seeds_a_plaintext_key_and_preserves_existing_values_on_rerun(): void
    {
        putenv('CLIENTCARE_SEED_API_KEY=test-only-key');

        app(MobileConfigurationSeeder::class)->run();
        $configuration = MobileConfiguration::where('config_key', 'loa_request_backend')->firstOrFail();
        $this->assertSame('system', $configuration->config_value);
        $this->assertSame('CORPORATE-API-KEY', $configuration->api_header);
        $this->assertSame(config('services.clientcare.base_url'), $configuration->api_url);
        $this->assertSame('test-only-key', $configuration->api_key);
        $this->assertSame('test-only-key', DB::connection('mysql')->table('mobile_configuration')->value('api_key'));

        $configuration->update([
            'config_value' => 'third_party',
            'api_header' => 'CUSTOM-KEY',
            'api_url' => 'https://example.test/portal',
        ]);
        putenv('CLIENTCARE_SEED_API_KEY=another-test-key');
        app(MobileConfigurationSeeder::class)->run();

        $configuration->refresh();
        $this->assertSame('third_party', $configuration->config_value);
        $this->assertSame('CUSTOM-KEY', $configuration->api_header);
        $this->assertSame('https://example.test/portal', $configuration->api_url);
        $this->assertSame('test-only-key', $configuration->api_key);
        $this->assertSame(1, MobileConfiguration::count());
    }

    public function test_it_adds_the_header_without_overwriting_existing_backend_when_no_key_is_supplied(): void
    {
        MobileConfiguration::create([
            'config_key' => 'loa_request_backend',
            'config_value' => 'third_party',
        ]);

        app(MobileConfigurationSeeder::class)->run();

        $configuration = MobileConfiguration::where('config_key', 'loa_request_backend')->firstOrFail();
        $this->assertSame('third_party', $configuration->config_value);
        $this->assertSame('CORPORATE-API-KEY', $configuration->api_header);
        $this->assertNull($configuration->api_key);
    }
}
