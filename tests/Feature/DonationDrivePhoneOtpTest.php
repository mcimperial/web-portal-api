<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Modules\DonationDrive\App\Models\DonationDonor;
use Modules\DonationDrive\App\Models\DonationDrive;
use Tests\TestCase;

class DonationDrivePhoneOtpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('donation_drive_donation_histories');
        Schema::dropIfExists('donation_drive_donations');
        Schema::dropIfExists('donation_drives');
        Schema::dropIfExists('donation_donors');

        Schema::create('donation_donors', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('normalized_name')->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });
        Schema::create('donation_drives', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        Schema::create('donation_drive_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_id')->constrained('donation_drives')->cascadeOnDelete();
            $table->foreignId('donor_id')->constrained('donation_donors')->cascadeOnDelete();
            $table->unsignedTinyInteger('cutoff');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['drive_id', 'donor_id']);
        });
        Schema::create('donation_drive_donation_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_id')->constrained('donation_drive_donations')->cascadeOnDelete();
            $table->decimal('previous_amount', 12, 2)->nullable();
            $table->decimal('new_amount', 12, 2);
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_contact_number_requires_a_name_when_it_is_not_registered(): void
    {
        $this->postJson('/api/v1/donation-drive/login/request-otp', [
            'phone' => '09171234567',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('profile');
    }

    public function test_otp_links_an_existing_name_only_donor_to_a_verified_phone(): void
    {
        $donor = DonationDonor::create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'normalized_name' => 'maria santos',
        ]);
        config()->set('services.sms', [
            'enabled' => true,
            'url' => 'http://sms-gateway.test/send',
            'username' => 'gateway-user',
            'password' => 'gateway-password',
            'timeout' => 20,
            'test_result' => ['success' => true, 'http_code' => 200, 'response' => []],
        ]);

        $this->postJson('/api/v1/donation-drive/login/request-otp', [
            'phone' => '09171234567',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ])->assertOk()
            ->assertJsonPath('channel', 'phone')
            ->assertJsonPath('masked_identifier', '0917*****67');

        $cacheKey = 'donation-drive:otp:phone:'.hash('sha256', '09171234567');
        $pending = Cache::get($cacheKey);
        $pending['otp_hash'] = Hash::make('123456');
        Cache::put($cacheKey, $pending, now()->addMinutes(5));

        $this->postJson('/api/v1/donation-drive/login/verify-otp', [
            'phone' => '09171234567',
            'otp' => '123456',
        ])->assertOk()
            ->assertJsonPath('donor.id', $donor->id)
            ->assertJsonStructure(['token']);

        $this->assertSame('09171234567', $donor->fresh()->phone);
        $this->assertNotNull($donor->fresh()->phone_verified_at);
        $this->assertDatabaseCount('donation_donors', 1);
    }

    public function test_a_donor_has_only_one_updatable_contribution_per_cause(): void
    {
        $donor = DonationDonor::create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'normalized_name' => 'maria santos',
            'phone' => '09171234567',
            'phone_verified_at' => now(),
        ]);
        $drive = DonationDrive::create([
            'title' => 'Community Relief',
            'is_active' => true,
        ]);
        Sanctum::actingAs($donor, ['donation-drive'], 'donation');

        $this->postJson("/api/v1/donation-drive/drives/{$drive->id}/donations", [
            'amount' => 500,
            'cutoff' => 1,
        ])->assertOk();
        $this->postJson("/api/v1/donation-drive/drives/{$drive->id}/donations", [
            'amount' => 750,
            'cutoff' => 2,
        ])->assertOk()
            ->assertJsonPath('data.amount', '750.00');

        $this->assertDatabaseCount('donation_drive_donations', 1);
        $this->assertDatabaseHas('donation_drive_donations', [
            'drive_id' => $drive->id,
            'donor_id' => $donor->id,
            'cutoff' => 2,
            'amount' => 750,
        ]);
    }

    public function test_a_new_donor_can_request_an_otp_by_email(): void
    {
        $sender = Mockery::mock('overload:Modules\ClientMasterlist\App\Services\EmailSender');
        $sender->shouldReceive('sendLlibi')->once()->andReturn(true);

        $this->postJson('/api/v1/donation-drive/login/request-otp', [
            'identifier' => 'Donor.Name@example.com',
            'first_name' => 'Donor',
            'last_name' => 'Name',
        ])->assertOk()
            ->assertJsonPath('channel', 'email')
            ->assertJsonPath('masked_identifier', 'do********@example.com');

        $cacheKey = 'donation-drive:otp:email:'.hash('sha256', 'donor.name@example.com');
        $this->assertNotNull(Cache::get($cacheKey));
    }
}
