<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMobileApp\App\Models\MobilePopup;
use Modules\ClientMobileApp\App\Models\MobileUser;
use Tests\TestCase;

class MobileAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mysql' => config('database.connections.sqlite')]);
        DB::purge('mysql');

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
        });
        Schema::create('user_role', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        });
        Schema::connection('mysql')->create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('mysql')->create('mobile_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('contact_number', 11)->nullable();
            $table->string('password');
            $table->string('member_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('mobile_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('config_key')->unique();
            $table->text('config_value');
            $table->string('api_header')->nullable();
            $table->text('api_key')->nullable();
            $table->string('api_url')->nullable();
            $table->timestamps();
        });
        $migration = require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_24_000000_create_mobile_popups_tables.php');
        $migration->up();
        $titleMigration = require base_path('Modules/ClientMobileApp/Database/migrations/2026_11_25_000000_add_title_to_mobile_popup_messages_table.php');
        $titleMigration->up();
    }

    private function loginAs(string $role = 'admin', bool $active = true): void
    {
        $roleId = DB::table('roles')->insertGetId(['slug' => $role]);
        DB::table('user_role')->insert(['user_id' => 1, 'role_id' => $roleId]);
        $user = new User();
        $user->forceFill(['id' => 1, 'is_active' => $active]);
        $this->actingAs($user, 'sanctum');
    }

    public function test_admin_routes_reject_unauthenticated_non_admin_and_inactive_users(): void
    {
        $this->getJson('/api/v1/mobile-admin/apis')->assertUnauthorized();
        $this->loginAs('admin-se');
        $this->getJson('/api/v1/mobile-admin/apis')->assertForbidden();
        DB::table('user_role')->delete();
        $this->loginAs('admin', false);
        $this->getJson('/api/v1/mobile-admin/apis')->assertForbidden();
    }

    public function test_popup_crud_preserves_message_ids_and_rejects_foreign_messages(): void
    {
        $this->loginAs();
        $payload = [
            'title' => 'Welcome', 'is_active' => true, 'show_decline_button' => false,
            'sort_order' => 1, 'messages' => [['html_content' => '<p>Hello</p>', 'sort_order' => 0]],
        ];
        $created = $this->postJson('/api/v1/mobile-admin/popups', $payload)->assertCreated();
        $id = $created->json('data.id');
        $messageId = $created->json('data.messages.0.id');
        $payload['messages'][0]['id'] = $messageId;
        $payload['messages'][0]['html_content'] = '<p>Updated</p>';
        $this->putJson("/api/v1/mobile-admin/popups/{$id}", $payload)
            ->assertOk()->assertJsonPath('data.messages.0.id', $messageId);
        $this->getJson('/api/v1/mobile/popups')->assertJsonPath('popups.0.messages.0.html_content', '<p>Updated</p>');
        $other = MobilePopup::create(['title' => 'Other']);
        $foreign = $other->messages()->create(['html_content' => 'Other']);
        $payload['messages'][0]['id'] = $foreign->id;
        $this->putJson("/api/v1/mobile-admin/popups/{$id}", $payload)->assertUnprocessable();
        $this->assertSame('Other', $foreign->fresh()->html_content);
        $this->deleteJson("/api/v1/mobile-admin/popups/{$id}")->assertOk();
        $this->getJson('/api/v1/mobile/popups')->assertJsonCount(0, 'popups');
        unset($payload['messages'][0]['id']);
        $payload['ends_at'] = now()->addDay()->toIso8601String();
        $this->postJson('/api/v1/mobile-admin/popups', $payload)->assertCreated();
        $payload['starts_at'] = now()->addDays(2)->toIso8601String();
        $this->postJson('/api/v1/mobile-admin/popups', $payload)->assertUnprocessable();
    }

    public function test_configuration_hides_keys_preserves_existing_key_and_validates_third_party(): void
    {
        $this->loginAs();
        DB::connection('mysql')->table('mobile_configuration')->insert([
            'config_key' => 'loa_request_backend', 'config_value' => 'system', 'api_key' => 'existing-secret',
        ]);
        $this->getJson('/api/v1/mobile-admin/configuration')->assertOk()->assertJsonMissing(['api_key' => 'existing-secret'])->assertJsonPath('has_api_key', true);
        $this->putJson('/api/v1/mobile-admin/configuration', ['config_value' => 'third_party', 'api_header' => 'X-KEY', 'api_url' => 'https://example.test'])
            ->assertOk()->assertJsonPath('has_api_key', true);
        $this->assertSame('existing-secret', DB::connection('mysql')->table('mobile_configuration')->value('api_key'));
        $this->putJson('/api/v1/mobile-admin/configuration', ['config_value' => 'third_party', 'api_header' => 'X-KEY', 'api_url' => 'https://example.test', 'api_key' => null])->assertUnprocessable();
        $this->putJson('/api/v1/mobile-admin/configuration', ['config_value' => 'third_party', 'api_header' => 'X-KEY', 'api_url' => 'ftp://example.test'])->assertUnprocessable();
    }

    public function test_message_titles_are_saved_as_overrides_and_blank_titles_follow_the_popup_title(): void
    {
        $this->loginAs();
        $payload = [
            'title' => 'Welcome', 'is_active' => true, 'show_decline_button' => false, 'sort_order' => 0,
            'messages' => [
                ['title' => '', 'html_content' => '<p><strong>Hello</strong></p>', 'sort_order' => 0],
                ['title' => 'Details', 'html_content' => '<p>More</p>', 'sort_order' => 1],
            ],
        ];
        $created = $this->postJson('/api/v1/mobile-admin/popups', $payload)
            ->assertCreated()->assertJsonPath('data.messages.0.title', null)
            ->assertJsonPath('data.messages.1.title', 'Details');
        $id = $created->json('data.id');
        $payload['title'] = 'New welcome';
        $payload['messages'][0]['id'] = $created->json('data.messages.0.id');
        $payload['messages'][1]['id'] = $created->json('data.messages.1.id');
        $payload['messages'][1]['title'] = '';
        $this->putJson("/api/v1/mobile-admin/popups/{$id}", $payload)->assertOk()
            ->assertJsonPath('data.messages.1.title', null);
        $this->getJson('/api/v1/mobile/popups')->assertOk()
            ->assertJsonPath('popups.0.messages.0.title', 'New welcome')
            ->assertJsonPath('popups.0.messages.0.html_content', '<p><strong>Hello</strong></p>')
            ->assertJsonPath('popups.0.messages.1.title', 'New welcome');
        $payload['messages'][1]['title'] = str_repeat('a', 256);
        $this->putJson("/api/v1/mobile-admin/popups/{$id}", $payload)->assertUnprocessable();
    }

    public function test_users_can_be_searched_updated_deactivated_revoked_and_deleted(): void
    {
        $this->loginAs();
        $mobile = MobileUser::create(['name' => 'Mobile User', 'email' => 'mobile@example.test', 'password' => 'hash', 'is_active' => true, 'email_verified_at' => now()]);
        $mobile->createToken('phone');
        $this->getJson('/api/v1/mobile-admin/users?search=mobile')->assertOk()->assertJsonCount(1, 'data');
        $this->putJson("/api/v1/mobile-admin/users/{$mobile->id}", [
            'name' => 'Updated', 'email' => 'updated@example.test', 'contact_number' => '09123456789', 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertNull($mobile->fresh()->email_verified_at);
        $this->assertSame(0, $mobile->tokens()->count());
        $mobile->createToken('another-phone');
        $this->postJson("/api/v1/mobile-admin/users/{$mobile->id}/revoke")->assertOk();
        $this->assertSame(0, $mobile->tokens()->count());
        $mobile->createToken('third-phone');
        $this->deleteJson("/api/v1/mobile-admin/users/{$mobile->id}")->assertOk();
        $this->assertSame(0, $mobile->tokens()->count());
        $this->assertNull($mobile->fresh());
    }

    public function test_api_catalog_includes_public_and_protected_mobile_routes(): void
    {
        $this->loginAs();
        $response = $this->getJson('/api/v1/mobile-admin/apis')->assertOk();
        $response->assertJsonFragment(['path' => '/api/v1/mobile/popups', 'authentication' => 'Public']);
        $response->assertJsonFragment(['path' => '/api/v1/mobile/me', 'authentication' => 'Mobile user']);
    }
}
