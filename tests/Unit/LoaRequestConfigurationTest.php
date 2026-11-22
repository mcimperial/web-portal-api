<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMobileData\App\Http\Controllers\LoaRequestController;
use Modules\ClientMobileData\App\Services\ClientCareLoaApiService;
use Tests\TestCase;

class LoaRequestConfigurationTest extends TestCase
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
            $table->string('config_key');
            $table->text('config_value');
            $table->string('api_header')->nullable();
            $table->text('api_key')->nullable();
            $table->string('api_url')->nullable();
        });
        DB::connection('mysql')->table('mobile_configuration')->insert([
            'config_key' => 'loa_request_backend',
            'config_value' => 'third_party',
        ]);
    }

    public function test_missing_third_party_configuration_is_rejected_before_any_request_is_saved(): void
    {
        $response = $this->submitRequest();

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('Your request was not submitted', $response->getData()->message);
    }

    private function submitRequest(): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/v1/mobile/loa/requests', 'POST', [
            'member_id' => 'TEST-MEMBER',
            'provider_id' => 1,
            'loa_type' => 'consultation',
            'complaint_id' => 1,
            'mobile_details' => [
                'patient_member_id' => 'TEST-MEMBER',
                'patient_details_source' => 'personal',
                'patient_last_name' => 'Test',
                'patient_first_name' => 'Patient',
                'patient_birth_date' => '1990-01-01',
            ],
        ]);
        $request->headers->set('Idempotency-Key', '53c98094-0f65-45f2-8082-ef21c2b85408');

        return app(LoaRequestController::class)->store($request, app(ClientCareLoaApiService::class));
    }
}
