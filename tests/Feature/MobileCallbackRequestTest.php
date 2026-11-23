<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\ClientMobileApp\App\Models\MobileCallbackRequest;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use Modules\ClientMobileApp\App\Models\MobileUser;
use Tests\TestCase;

class MobileCallbackRequestTest extends TestCase
{
    private const ENDPOINT = '/api/v1/mobile/callback-requests';

    private const PROVIDER_URL = 'https://provider-api.llibi.app/api/corporate/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'services.clientcare.callback_url' => self::PROVIDER_URL,
        ]);
        DB::purge('mysql');
        Http::preventStrayRequests();

        Schema::connection('mysql')->create('mobile_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('contact_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('mobile_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('config_key');
            $table->string('config_value');
            $table->string('api_header')->nullable();
            $table->string('api_key')->nullable();
            $table->string('api_url')->nullable();
            $table->timestamps();
        });
        $migration = require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_26_000000_create_mobile_callback_requests_table.php');
        $migration->up();
        MobileConfiguration::create([
            'config_key' => 'loa_request_backend',
            'config_value' => 'system',
            'api_header' => 'CORPORATE-API-KEY',
            'api_key' => 'test-only-key',
            'api_url' => null,
        ]);
    }

    private function user(string $email = 'member@example.test'): MobileUser
    {
        return MobileUser::create([
            'name' => 'Test Requestor', 'email' => $email, 'password' => 'test-password',
            'contact_number' => '09171234567', 'is_active' => true,
        ]);
    }

    private function payload(): array
    {
        return [
            'email' => 'edited@example.test', 'mobile' => '09981234567',
            'request' => 'Please call about my appointment.',
            'landline' => '02 8123 4567', 'alt_email' => 'alternate@example.test',
            'alt_landline' => '02 8765 4321', 'alt_mobile' => '09171234567',
        ];
    }

    public function test_only_active_mobile_accounts_can_access_callbacks(): void
    {
        Http::fake();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnauthorized();
        $this->actingAs(new User(['id' => 10, 'is_active' => true]), 'mobile');
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $inactive = $this->user();
        $inactive->update(['is_active' => false]);
        $this->actingAs($inactive, 'mobile');
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_submission_uses_the_existing_key_new_url_and_exact_provider_payload(): void
    {
        Http::fake([self::PROVIDER_URL => Http::response(['message' => 'Received'], 201)]);
        $user = $this->user();
        $this->actingAs($user, 'mobile');
        $payload = $this->payload();
        $payload['user'] = ['provider_id' => 99, 'keyword' => 'Spoofed name'];
        $payload['platform'] = 'portal';

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ENDPOINT, $payload)
            ->assertCreated()->assertJsonPath('request.status', 'submitted');
        $this->assertArrayNotHasKey('idempotency_key', $response->json('request'));
        $this->assertArrayNotHasKey('request_hash', $response->json('request'));
        $this->assertSame('member@example.test', $user->fresh()->email);
        $this->assertSame('09171234567', $user->fresh()->contact_number);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::PROVIDER_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('CORPORATE-API-KEY', 'test-only-key')
            && $request->data() === [
                'email' => 'edited@example.test', 'mobile' => '09981234567',
                'landline' => '02 8123 4567', 'platform' => 'mobile',
                'data' => [
                    'request' => 'Please call about my appointment.', 'alt_email' => 'alternate@example.test',
                    'alt_landline' => '02 8765 4321', 'alt_mobile' => '09171234567',
                ],
                'user' => ['provider_id' => null, 'keyword' => 'Test Requestor'],
            ]);
        Http::assertSentCount(1);
        $this->assertSame(1, MobileCallbackRequest::count());
    }

    public function test_optional_contacts_are_null_and_replays_do_not_send_again(): void
    {
        Http::fake([self::PROVIDER_URL => Http::response([], 202)]);
        $this->actingAs($this->user(), 'mobile');
        $payload = ['email' => 'member@example.test', 'mobile' => '09171234567', 'request' => 'Please call.'];
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $created = $this->postJson(self::ENDPOINT, $payload)->assertCreated();
        $this->postJson(self::ENDPOINT, $payload)
            ->assertOk()->assertJsonPath('request.id', $created->json('request.id'));
        $this->postJson(self::ENDPOINT, [...$payload, 'request' => 'Different request'])->assertConflict();
        Http::assertSent(fn (ClientRequest $request) => $request['landline'] === null
            && $request['data'] === [
                'request' => 'Please call.', 'alt_email' => null, 'alt_landline' => null, 'alt_mobile' => null,
            ]);
        Http::assertSentCount(1);
    }

    public function test_invalid_contacts_and_missing_keys_are_rejected_without_forwarding(): void
    {
        Http::fake();
        $this->actingAs($this->user(), 'mobile');
        $this->postJson(self::ENDPOINT, $this->payload())->assertUnprocessable();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ENDPOINT, [
                ...$this->payload(), 'email' => '', 'mobile' => '123', 'request' => ' ',
                'alt_email' => 'invalid', 'alt_mobile' => '123',
            ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile', 'request', 'alt_email', 'alt_mobile']);
        Http::assertNothingSent();
        $this->assertSame(0, MobileCallbackRequest::count());
    }

    public function test_missing_configuration_is_reported_without_forwarding(): void
    {
        Http::fake();
        MobileConfiguration::query()->update(['api_key' => null]);
        $this->actingAs($this->user(), 'mobile');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::ENDPOINT, $this->payload())->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, MobileCallbackRequest::count());
    }

    public function test_provider_rejections_and_uncertain_responses_are_not_reported_as_success(): void
    {
        Http::fake([self::PROVIDER_URL => Http::sequence()
            ->push(['message' => 'Unauthorized'], 200)
            ->push(['success' => false], 200)
            ->push(['message' => 'Invalid'], 422)
            ->push([], 500)
            ->push([], 302)]);
        $this->actingAs($this->user(), 'mobile');

        foreach (['failed', 'failed', 'failed', 'unconfirmed', 'failed'] as $status) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson(self::ENDPOINT, $this->payload())
                ->assertStatus(502)->assertJsonPath('request.status', $status);
        }
        Http::assertSentCount(5);
    }

    public function test_timeout_is_recorded_and_cannot_be_submitted_twice_with_the_same_key(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Test timeout');
        });
        $this->actingAs($this->user(), 'mobile');
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(502)->assertJsonPath('request.status', 'unconfirmed');
        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertStatus(502)->assertJsonPath('request.status', 'unconfirmed');
        $this->assertSame(1, MobileCallbackRequest::count());
        $this->assertSame(1, $calls);
    }

    public function test_history_is_private_paginated_and_newest_first(): void
    {
        $owner = $this->user();
        $other = $this->user('other@example.test');
        foreach (range(1, 12) as $index) {
            MobileCallbackRequest::create([
                'mobile_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid(),
                'request_hash' => str_repeat('a', 64), 'requestor_name' => $owner->name,
                'email' => $owner->email, 'mobile' => '09171234567',
                'request_message' => "Owner request {$index}", 'status' => 'submitted',
            ]);
        }
        MobileCallbackRequest::create([
            'mobile_user_id' => $other->id, 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('b', 64), 'requestor_name' => $other->name,
            'email' => $other->email, 'mobile' => '09171234567',
            'request_message' => 'Private other request', 'status' => 'submitted',
        ]);
        $this->actingAs($owner, 'mobile');
        $this->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('total', 12)
            ->assertJsonPath('data.0.request_message', 'Owner request 12')
            ->assertJsonMissing(['request_message' => 'Private other request']);
        $this->getJson(self::ENDPOINT.'?page=2')->assertOk()->assertJsonCount(2, 'data');
    }
}
