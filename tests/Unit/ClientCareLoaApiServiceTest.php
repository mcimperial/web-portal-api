<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use Modules\ClientMobileApp\App\Models\MobileUser;
use Modules\ClientMobileApp\App\Http\Controllers\LoaRequestController;
use Modules\ClientMobileApp\App\Models\Doctor;
use Modules\ClientMobileApp\App\Models\Hospital;
use Modules\ClientMobileApp\App\Models\Masterlist;
use Modules\ClientMobileApp\App\Services\ClientCareAuthenticationException;
use Modules\ClientMobileApp\App\Services\ClientCareLoaApiService;
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
        $payload = [
            'verificationDetailsType' => 'insurance',
            'erCardNumber' => 'TEST-MEMBER',
            'dob' => '1990-01-01',
            'patientType' => 'employee',
            'patientFirstName' => 'Jane',
            'patientLastName' => 'Doe',
            'employeeFirstName' => 'Jane',
            'employeeLastName' => 'Doe',
            'email' => 'jane@example.test',
            'provider' => '12||Test Hospital--0||--',
            'loaType' => 'laboratory',
            'cceId' => 'null',
        ];
        app(ClientCareLoaApiService::class)->submitLaboratory(
            $configuration,
            $payload,
            'test PDF content',
            'request.pdf'
        );

        Http::assertSent(function (Request $request) use ($payload) {
            $fields = collect($request->data())
                ->filter(fn ($part) => $part['name'] !== 'files[]')
                ->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])
                ->all();

            return $request->method() === 'POST'
                && $request->url() === 'https://clientcare-api.llibi.app/api/portal/laboratory-request'
                && $request->hasHeader('CORPORATE-API-KEY', 'test-only-key')
                && $request->isMultipart()
                && $fields === $payload
                && $request->hasFile('files[]', 'test PDF content', 'request.pdf');
        });
    }

    #[DataProvider('providerDoctors')]
    public function test_consultation_provider_includes_doctor_segment(?array $doctorData, string $expectedProvider): void
    {
        Http::fake(['https://clientcare.example.test/*' => Http::response([], 201)]);

        $configuration = new MobileConfiguration([
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => 'https://clientcare.example.test/api/portal',
        ]);
        $patient = new Masterlist([
            'member_id' => 'TEST-MEMBER',
            'birth_date' => '1990-01-01',
            'relation' => 'EMPLOYEE',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $hospital = new Hospital(['id' => 12, 'name' => 'Test Hospital']);
        $doctor = $doctorData ? new Doctor($doctorData) : null;
        $request = \Illuminate\Http\Request::create('/');
        $request->merge(['mobile_details' => [
            'alternate_email' => 'alternate@example.test',
            'contact_number' => '09170000000',
        ]]);
        $request->setUserResolver(fn () => new MobileUser(['email' => 'jane@example.test']));

        $method = new \ReflectionMethod(LoaRequestController::class, 'submitThirdPartyRequest');
        $method->invoke(
            app(LoaRequestController::class),
            app(ClientCareLoaApiService::class),
            $configuration,
            $request,
            'consultation',
            $patient,
            $patient,
            $hospital,
            $doctor,
            'HEADACHE',
            null
        );

        Http::assertSent(fn (Request $outgoing) => $outgoing->isJson()
            && $outgoing->url() === 'https://clientcare.example.test/api/portal/consultation-request'
            && $outgoing->hasHeader('CORPORATE-API-KEY', 'test-only-key')
            && $outgoing->data() === [
                'verificationDetailsType' => 'insurance',
                'erCardNumber' => 'TEST-MEMBER',
                'dob' => '1990-01-01',
                'patientType' => 'employee',
                'patientFirstName' => 'Jane',
                'patientLastName' => 'Doe',
                'employeeFirstName' => 'Jane',
                'employeeLastName' => 'Doe',
                'email' => 'jane@example.test',
                'alt_email' => 'alternate@example.test',
                'contact' => '09170000000',
                'platform' => 'mobile',
                'provider' => $expectedProvider,
                'loaType' => 'consultation',
                'complaint' => [['label' => 'HEADACHE']],
            ]);
    }

    public function test_blank_optional_contact_details_are_omitted_from_consultation_payload(): void
    {
        Http::fake(['https://clientcare.example.test/*' => Http::response([], 201)]);

        $configuration = new MobileConfiguration([
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => 'https://clientcare.example.test/api/portal',
        ]);
        $patient = new Masterlist([
            'member_id' => 'TEST-MEMBER',
            'birth_date' => '1990-01-01',
            'relation' => 'EMPLOYEE',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $request = \Illuminate\Http\Request::create('/');
        $request->merge(['mobile_details' => [
            'alternate_email' => null,
            'contact_number' => '',
        ]]);
        $request->setUserResolver(fn () => new MobileUser(['email' => 'jane@example.test']));

        $method = new \ReflectionMethod(LoaRequestController::class, 'submitThirdPartyRequest');
        $method->invoke(
            app(LoaRequestController::class),
            app(ClientCareLoaApiService::class),
            $configuration,
            $request,
            'consultation',
            $patient,
            $patient,
            new Hospital(['id' => 12, 'name' => 'Test Hospital']),
            null,
            'HEADACHE',
            null
        );

        Http::assertSent(fn (Request $outgoing) => $outgoing->isJson()
            && ! array_key_exists('alt_email', $outgoing->data())
            && ! array_key_exists('contact', $outgoing->data())
            && $outgoing->data()['platform'] === 'mobile');
    }

    #[DataProvider('providerDoctors')]
    public function test_laboratory_provider_includes_doctor_segment(?array $doctorData, string $expectedProvider): void
    {
        Http::fake(['https://clientcare.example.test/*' => Http::response([], 201)]);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('get')->once()->with('request.pdf')->andReturn('test PDF content');
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $configuration = new MobileConfiguration([
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => 'https://clientcare.example.test/api/portal',
        ]);
        $patient = new Masterlist([
            'member_id' => 'TEST-MEMBER',
            'birth_date' => '1990-01-01',
            'relation' => 'EMPLOYEE',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $hospital = new Hospital(['id' => 12, 'name' => 'Test Hospital']);
        $doctor = $doctorData ? new Doctor($doctorData) : null;
        $request = \Illuminate\Http\Request::create('/');
        $request->merge(['mobile_details' => [
            'alternate_email' => 'alternate@example.test',
            'contact_number' => '09170000000',
        ]]);
        $request->files->set('doctor_request', UploadedFile::fake()->createWithContent('request.pdf', 'test PDF content'));
        $request->setUserResolver(fn () => new MobileUser(['email' => 'jane@example.test']));

        $method = new \ReflectionMethod(LoaRequestController::class, 'submitThirdPartyRequest');
        $method->invoke(
            app(LoaRequestController::class),
            app(ClientCareLoaApiService::class),
            $configuration,
            $request,
            'laboratory',
            $patient,
            $patient,
            $hospital,
            $doctor,
            'Laboratory request',
            'request.pdf'
        );

        Http::assertSent(function (Request $outgoing) use ($expectedProvider) {
            $fields = collect($outgoing->data())
                ->filter(fn ($part) => $part['name'] !== 'files[]')
                ->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])
                ->all();

            return $outgoing->isMultipart()
                && $outgoing->url() === 'https://clientcare.example.test/api/portal/laboratory-request'
                && $fields['provider'] === $expectedProvider
                && $fields['cceId'] === 'null'
                && $fields['alt_email'] === 'alternate@example.test'
                && $fields['contact'] === '09170000000'
                && $fields['platform'] === 'mobile'
                && $outgoing->hasFile('files[]', 'test PDF content', 'request.pdf');
        });
    }

    public static function providerDoctors(): array
    {
        return [
            'without doctor' => [null, '12||Test Hospital--0||--'],
            'with doctor' => [
                ['id' => 34, 'first' => 'Pat', 'last' => 'Smith'],
                '12||Test Hospital--34||Pat Smith',
            ],
        ];
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
