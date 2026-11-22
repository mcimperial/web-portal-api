<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\ClientMobileApp\App\Models\MobileUser;
use Modules\ClientMobileData\App\Models\Masterlist;
use Tests\TestCase;

class MobileComplaintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['mysql', 'mysql_masterlist', 'mysql_sync'] as $connection) {
            config(["database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]]);
            DB::purge($connection);
        }

        $migration = require base_path('Modules/ClientMobileData/Database/migrations/2026_11_22_000008_create_mobile_complaints_table.php');
        $migration->up();
        Schema::connection('mysql_masterlist')->create('app_portal_complaints', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->boolean('is_status');
        });
        DB::connection('mysql_masterlist')->table('app_portal_complaints')->insert([
            'id' => 1,
            'title' => 'Headache',
            'is_status' => 1,
        ]);
    }

    public function test_new_complaints_are_shared_and_duplicates_use_existing_entries(): void
    {
        $this->postJson('/api/v1/mobile/complaints/custom', ['title' => 'Eye strain'])
            ->assertUnauthorized();

        $this->actingAs($this->mobileUser('first@example.test'), 'mobile');
        $created = $this->postJson('/api/v1/mobile/complaints/custom', ['title' => '  Eye strain  '])
            ->assertCreated()
            ->assertJsonPath('complaint.title', 'Eye strain')
            ->assertJsonPath('complaint.source', 'custom');
        $id = $created->json('complaint.id');

        $this->postJson('/api/v1/mobile/complaints/custom', ['title' => 'eye STRAIN'])
            ->assertOk()
            ->assertJsonPath('complaint.id', $id);
        $this->postJson('/api/v1/mobile/complaints/custom', ['title' => 'HEADACHE'])
            ->assertOk()
            ->assertJsonPath('complaint.source', 'catalog')
            ->assertJsonPath('complaint.id', 1);
        $this->postJson('/api/v1/mobile/complaints/custom', ['title' => '   '])
            ->assertUnprocessable();
        $this->assertSame(1, DB::connection('mysql')->table('mobile_complaints')->count());
        $this->assertSame(1, DB::connection('mysql_masterlist')->table('app_portal_complaints')->count());

        $this->actingAs($this->mobileUser('second@example.test'), 'mobile');
        $this->getJson('/api/v1/mobile/complaints/custom?search=strain')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.source', 'custom');
    }

    public function test_custom_and_catalog_complaints_are_resolved_by_source_for_loa_submission(): void
    {
        $this->createLoaTables();
        $this->actingAs($this->mobileUser('member@example.test', 'MEM001'), 'mobile');
        $this->postJson('/api/v1/mobile/complaints/custom', ['title' => 'Eye strain'])
            ->assertCreated()
            ->assertJsonPath('complaint.id', 1);

        $this->fakeClientCareLoaApi();
        $this->withHeader('Idempotency-Key', '53c98094-0f65-45f2-8082-ef21c2b85408')
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload(['complaint_source' => 'custom']))
            ->assertCreated()
            ->assertJsonPath('request', null);
        Http::assertSent(fn (ClientRequest $request) => $request->data()['complaint'] === [['label' => 'Eye strain']]);

        $this->withHeader('Idempotency-Key', '687de31f-e52b-4dd1-8c43-ebcb80d3e259')
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertCreated()
            ->assertJsonPath('request', null);
        $this->withHeader('Idempotency-Key', '401df042-b7fb-4155-93f6-ddb88df3794a')
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload([
                'complaint_id' => 999,
                'complaint_source' => 'custom',
            ]))->assertUnprocessable();
        $this->assertSame(2, DB::connection('mysql_masterlist')->table('app_portal_requests')->count());
        $this->assertSame(
            ['Eye strain', 'Headache'],
            DB::connection('mysql_masterlist')->table('app_portal_requests')->orderBy('id')->pluck('complaint')->all()
        );
    }

    public function test_replayed_consultation_and_laboratory_submissions_create_one_portal_row_each(): void
    {
        $this->createLoaTables();
        Storage::fake('local');
        $this->actingAs($this->mobileUser('member@example.test', 'MEM001'), 'mobile');
        $this->fakeClientCareLoaApi();

        $this->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertUnprocessable();

        $consultationKey = '53c98094-0f65-45f2-8082-ef21c2b85408';
        $this->withHeader('Idempotency-Key', $consultationKey)
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertCreated()
            ->assertJsonPath('request', null);
        $this->withHeader('Idempotency-Key', $consultationKey)
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertOk()
            ->assertJsonPath('request', null);
        $changedConsultation = $this->loaPayload();
        $changedConsultation['mobile_details']['notes'] = 'Different request';
        $this->withHeader('Idempotency-Key', $consultationKey)
            ->postJson('/api/v1/mobile/loa/requests', $changedConsultation)
            ->assertConflict();

        $laboratoryKey = '687de31f-e52b-4dd1-8c43-ebcb80d3e259';
        $laboratoryPayload = $this->loaPayload(['loa_type' => 'laboratory']);
        unset($laboratoryPayload['complaint_id']);
        $laboratoryPayload['doctor_request'] = UploadedFile::fake()->createWithContent(
            'doctor-request.pdf',
            'test laboratory request'
        );
        $this->withHeader('Idempotency-Key', $laboratoryKey)
            ->post('/api/v1/mobile/loa/requests', $laboratoryPayload)
            ->assertCreated()
            ->assertJsonPath('request', null);

        $laboratoryPayload['doctor_request'] = UploadedFile::fake()->createWithContent(
            'doctor-request.pdf',
            'test laboratory request'
        );
        $this->withHeader('Idempotency-Key', $laboratoryKey)
            ->post('/api/v1/mobile/loa/requests', $laboratoryPayload)
            ->assertOk()
            ->assertJsonPath('request', null);

        $this->assertSame(2, DB::connection('mysql_masterlist')->table('app_portal_requests')->count());
        $this->assertSame(0, DB::connection('mysql')->table('mobile_loa_request_details')->count());
        $this->assertSame(2, DB::connection('mysql')->table('mobile_loa_request_submissions')->count());
        $storedDetails = json_decode(
            DB::connection('mysql')->table('mobile_loa_request_submissions')
                ->where('idempotency_key', $laboratoryKey)->value('details'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('MEM001', $storedDetails['mobile_details']['patient_member_id']);
        $this->assertSame('doctor-request.pdf', $storedDetails['mobile_details']['doctor_request_name']);
        $this->assertSame('laboratory', $storedDetails['loa_type']);
        $this->assertCount(0, Storage::disk('local')->allFiles('mobile-loa-requests'));
        Http::assertSentCount(2);
    }

    public function test_failed_clientcare_submission_is_not_resent_on_replay(): void
    {
        $this->createLoaTables();
        $this->actingAs($this->mobileUser('member@example.test', 'MEM001'), 'mobile');
        Http::fake(['https://clientcare.example.test/*' => Http::response([], 500)]);

        $key = '53c98094-0f65-45f2-8082-ef21c2b85408';
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertStatus(502);
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertConflict();

        Http::assertSentCount(1);
        $this->assertSame(0, DB::connection('mysql_masterlist')->table('app_portal_requests')->count());
    }

    public function test_system_submission_still_creates_a_portal_request_and_linked_details(): void
    {
        $this->createLoaTables();
        DB::connection('mysql')->table('mobile_configuration')->update(['config_value' => 'system']);
        $this->actingAs($this->mobileUser('member@example.test', 'MEM001'), 'mobile');
        Http::fake();

        $this->withHeader('Idempotency-Key', '53c98094-0f65-45f2-8082-ef21c2b85408')
            ->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertCreated()
            ->assertJsonPath('request.complaint', 'Headache');

        $this->assertSame(1, DB::connection('mysql_masterlist')->table('app_portal_requests')->count());
        $this->assertSame(1, DB::connection('mysql')->table('mobile_loa_request_details')->count());
        Http::assertNothingSent();
    }

    private function mobileUser(string $email, ?string $memberId = null): MobileUser
    {
        return new MobileUser(['email' => $email, 'is_active' => true, 'member_id' => $memberId]);
    }

    private function fakeClientCareLoaApi(): void
    {
        Http::fake(function (ClientRequest $request) {
            $payload = $request->data();
            if ($request->isMultipart()) {
                $payload = collect($payload)
                    ->filter(fn (array $part) => $part['name'] !== 'files[]')
                    ->mapWithKeys(fn (array $part) => [$part['name'] => $part['contents']])
                    ->all();
            }

            DB::connection('mysql_masterlist')
                ->table('app_portal_requests')
                ->insertGetId([
                    'member_id' => $payload['erCardNumber'],
                    'provider_id' => 1,
                    'provider' => 'Test Hospital',
                    'loa_type' => $payload['loaType'],
                    'complaint' => $payload['loaType'] === 'consultation'
                        ? $payload['complaint'][0]['label']
                        : 'Laboratory request',
                    'loa_status' => 'Pending Approval',
                ]);

            return Http::response(['message' => 'Request accepted'], 200);
        });
    }

    private function loaPayload(array $overrides = []): array
    {
        return array_replace([
            'member_id' => 'MEM001',
            'provider_id' => 1,
            'loa_type' => 'consultation',
            'complaint_id' => 1,
            'mobile_details' => [
                'patient_member_id' => 'MEM001',
                'patient_details_source' => 'personal',
                'patient_last_name' => 'Patient',
                'patient_first_name' => 'Test',
                'patient_birth_date' => '1990-01-01',
            ],
        ], $overrides);
    }

    private function createLoaTables(): void
    {
        Schema::connection('mysql')->create('mobile_configuration', function (Blueprint $table) {
            $table->string('config_key');
            $table->string('config_value');
            $table->string('api_header')->nullable();
            $table->string('api_key')->nullable();
            $table->string('api_url')->nullable();
        });
        DB::connection('mysql')->table('mobile_configuration')->insert([
            'config_key' => 'loa_request_backend',
            'config_value' => 'third_party',
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => 'https://clientcare.example.test/api/portal',
        ]);
        Schema::connection('mysql')->create('mobile_loa_request_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_request_id')->unique();
            $table->string('member_id');
            $table->json('details');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('mobile_loa_request_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->string('member_id', 15);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('portal_request_id')->nullable()->unique();
            $table->string('status', 20)->default('processing');
            $table->timestamps();
        });
        $migration = require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_23_000001_add_details_to_mobile_loa_request_submissions.php');
        $migration->up();
        Schema::connection('mysql_sync')->create('masterlist', function (Blueprint $table) {
            $table->id();
            foreach (array_diff(Masterlist::PUBLIC_COLUMNS, ['id']) as $column) {
                $table->string($column)->nullable();
            }
        });
        DB::connection('mysql_sync')->table('masterlist')->insert([
            'member_id' => 'MEM001',
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'relation' => 'EMPLOYEE',
        ]);
        Schema::connection('mysql_sync')->create('hospitals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status');
            $table->boolean('accredited');
        });
        DB::connection('mysql_sync')->table('hospitals')->insert([
            'id' => 1,
            'name' => 'Test Hospital',
            'status' => 1,
            'accredited' => 1,
        ]);
        Schema::connection('mysql_masterlist')->create('app_portal_requests', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable();
            $table->string('member_id');
            $table->unsignedBigInteger('provider_id');
            $table->string('provider');
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->string('doctor_name')->nullable();
            $table->string('loa_type');
            $table->string('provider_procedure_type')->nullable();
            $table->string('complaint');
            $table->string('diagnosis')->nullable();
            $table->string('loa_number')->nullable();
            $table->string('loa_attachment')->nullable();
            $table->string('approval_code')->nullable();
            $table->string('loa_status');
            $table->date('used_date')->nullable();
            $table->timestamps();
        });
    }
}
