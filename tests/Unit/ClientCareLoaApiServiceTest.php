<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use Modules\ClientMobileData\App\Services\ClientCareAuthenticationException;
use Modules\ClientMobileData\App\Services\ClientCareLoaApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientCareLoaApiServiceTest extends TestCase
{
    public function test_credentials_are_plaintext_in_storage_and_hidden_from_serialization(): void
    {
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
            $table->string('config_key');
            $table->text('config_value');
            $table->string('api_header')->nullable();
            $table->text('api_key')->nullable();
            $table->string('api_url')->nullable();
            $table->timestamps();
        });

        $configuration = MobileConfiguration::create([
            'config_key' => 'loa_request_backend',
            'config_value' => 'third_party',
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
        ]);
        $stored = DB::connection('mysql')->table('mobile_configuration')->value('api_key');

        $this->assertSame('test-only-key', $stored);
        $this->assertSame('test-only-key', $configuration->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $configuration->toArray());
    }

    public function test_consultation_uses_corporate_header_and_json_payload(): void
    {
        Http::fake([
            'https://clientcare.example.test/custom/portal/*' => Http::response([], 201),
        ]);

        $payload = [
            'loaType' => 'consultation',
            'complaint' => [['label' => 'HEADACHE']],
        ];

        $configuration = new MobileConfiguration([
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => 'https://clientcare.example.test/custom/portal',
        ]);
        app(ClientCareLoaApiService::class)->submitConsultation($configuration, $payload);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://clientcare.example.test/custom/portal/consultation-request'
            && $request->hasHeader('CORPORATE-API-KEY', 'test-only-key')
            && $request->data() === $payload);
    }

    public function test_laboratory_includes_multipart_attachment(): void
    {
        Http::fake([
            'https://clientcare-api.llibi.app/api/portal/*' => Http::response([], 201),
        ]);

        $configuration = new MobileConfiguration([
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => 'https://clientcare-api.llibi.app/api/portal/',
        ]);
        app(ClientCareLoaApiService::class)->submitLaboratory(
            $configuration,
            ['loaType' => 'laboratory', 'cceId' => 'null'],
            'test PDF content',
            'request.pdf'
        );

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://clientcare-api.llibi.app/api/portal/laboratory-request'
            && $request->hasHeader('CORPORATE-API-KEY', 'test-only-key')
            && str_contains($request->body(), 'name="files[]"')
            && str_contains($request->body(), 'request.pdf'));
    }

    public function test_missing_or_invalid_url_is_not_configured(): void
    {
        $configuration = new MobileConfiguration([
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
        ]);
        $service = app(ClientCareLoaApiService::class);
        $this->assertFalse($service->isConfigured($configuration));

        $configuration->api_url = 'not-a-url';
        $this->assertFalse($service->isConfigured($configuration));
    }

    #[DataProvider('loaTypes')]
    public function test_unauthorized_response_with_http_200_is_not_treated_as_success(string $type): void
    {
        Http::fake([
            'https://clientcare.example.test/*' => Http::response(['message' => 'Unauthorized'], 200),
        ]);
        $configuration = new MobileConfiguration([
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => 'https://clientcare.example.test/api/portal',
        ]);
        $service = app(ClientCareLoaApiService::class);

        $this->expectException(ClientCareAuthenticationException::class);

        if ($type === 'consultation') {
            $service->submitConsultation($configuration, ['loaType' => $type]);
        } else {
            $service->submitLaboratory($configuration, ['loaType' => $type], 'test file', 'request.pdf');
        }
    }

    public static function loaTypes(): array
    {
        return [
            'consultation' => ['consultation'],
            'laboratory' => ['laboratory'],
        ];
    }
}
