<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

        Http::fake(['https://clientcare.example.test/*' => Http::response([], 201)]);
        $this->postJson('/api/v1/mobile/loa/requests', $this->loaPayload(['complaint_source' => 'custom']))
            ->assertCreated()
            ->assertJsonPath('request.complaint', 'Eye strain');
        Http::assertSent(fn (ClientRequest $request) => $request->data()['complaint'] === [['label' => 'Eye strain']]);

        $this->postJson('/api/v1/mobile/loa/requests', $this->loaPayload())
            ->assertCreated()
            ->assertJsonPath('request.complaint', 'Headache');
        $this->postJson('/api/v1/mobile/loa/requests', $this->loaPayload([
            'complaint_id' => 999,
            'complaint_source' => 'custom',
        ]))->assertUnprocessable();
        $this->assertSame(2, DB::connection('mysql_masterlist')->table('app_portal_requests')->count());
    }

    private function mobileUser(string $email, ?string $memberId = null): MobileUser
    {
        return new MobileUser(['email' => $email, 'is_active' => true, 'member_id' => $memberId]);
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
            $table->unsignedBigInteger('portal_request_id');
            $table->string('member_id');
            $table->json('details');
            $table->timestamps();
        });
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
            $table->string('member_id');
            $table->unsignedBigInteger('provider_id');
            $table->string('provider');
            $table->unsignedBigInteger('doctor_id')->nullable();
            $table->string('doctor_name')->nullable();
            $table->string('loa_type');
            $table->string('complaint');
            $table->string('loa_attachment')->nullable();
            $table->string('loa_status');
            $table->timestamps();
        });
    }
}
