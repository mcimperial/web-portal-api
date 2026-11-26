<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMobileApp\App\Models\MobileUser;
use Tests\TestCase;

class MobileAccountSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mysql' => config('database.connections.sqlite')]);
        DB::purge('mysql');

        Schema::connection('mysql')->create('mobile_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('contact_number', 11)->nullable();
            $table->string('password');
            $table->string('device_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    private function user(string $email = 'juan@example.test'): MobileUser
    {
        return MobileUser::create([
            'name' => 'Juan Dela Cruz',
            'email' => $email,
            'contact_number' => '09171234567',
            'password' => Hash::make('current-password'),
            'is_active' => true,
        ]);
    }

    public function test_account_settings_require_mobile_authentication(): void
    {
        $this->putJson('/api/v1/mobile/account')->assertUnauthorized();
        $this->putJson('/api/v1/mobile/account/password')->assertUnauthorized();
    }

    public function test_user_can_update_account_details(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'mobile');

        $this->putJson('/api/v1/mobile/account', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'MARIA@EXAMPLE.TEST',
            'contact_number' => '09981234567',
        ])->assertOk()
            ->assertJsonPath('user.name', 'Maria Santos')
            ->assertJsonPath('user.email', 'maria@example.test')
            ->assertJsonPath('user.contact_number', '09981234567');

        $user->refresh();
        $this->assertSame('Maria Santos', $user->name);
        $this->assertSame('maria@example.test', $user->email);
        $this->assertSame('09981234567', $user->contact_number);
    }

    public function test_user_cannot_reuse_another_accounts_email_or_invalid_phone(): void
    {
        $this->user('existing@example.test');
        $user = $this->user();
        $this->actingAs($user, 'mobile');

        $this->putJson('/api/v1/mobile/account', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'existing@example.test',
            'contact_number' => '9123456789',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'contact_number']);
    }

    public function test_user_can_change_password_with_current_password(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'mobile');

        $this->putJson('/api/v1/mobile/account/password', [
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_password_change_rejects_an_incorrect_current_password(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'mobile');

        $this->putJson('/api/v1/mobile/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }
}
