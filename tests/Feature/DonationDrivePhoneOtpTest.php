<?php

namespace Tests\Feature;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RequireModulePermission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Modules\DonationDrive\App\Models\DonationDonor;
use Modules\DonationDrive\App\Models\DonationDrive;
use Tests\TestCase;

class DonationDrivePhoneOtpTest extends TestCase
{
    private ?string $documentTestRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('donation_drive_histories');
        Schema::dropIfExists('donation_drive_documents');
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
            $table->boolean('is_closed')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('donation_drive_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_id')->constrained('donation_drives')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('storage_disk', 32)->default('local');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
        });
        Schema::create('donation_drive_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drive_id')->constrained('donation_drives')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->boolean('previous_is_active')->nullable();
            $table->boolean('current_is_active');
            $table->json('details')->nullable();
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

    protected function tearDown(): void
    {
        if ($this->documentTestRoot !== null) {
            File::deleteDirectory($this->documentTestRoot);
        }
        parent::tearDown();
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

    public function test_admin_can_close_and_reopen_a_visible_drive(): void
    {
        $this->withoutMiddleware(RequireModulePermission::class);
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->id = 1;
        $admin->shouldReceive('isSuperAdmin')->andReturnTrue();
        Sanctum::actingAs($admin);
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        $inactive = DonationDrive::create(['title' => 'Draft', 'is_active' => false]);

        $this->patchJson("/api/v1/donation-drive/admin/drives/{$inactive->id}/closure", ['is_closed' => true])
            ->assertUnprocessable();
        $this->patchJson("/api/v1/donation-drive/admin/drives/{$drive->id}/closure", ['is_closed' => true])
            ->assertOk()->assertJsonPath('data.is_closed', true)->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('donation_drive_histories', ['drive_id' => $drive->id, 'action' => 'closed', 'user_id' => 1]);
        $this->patchJson("/api/v1/donation-drive/admin/drives/{$drive->id}/closure", ['is_closed' => false])
            ->assertOk()->assertJsonPath('data.is_closed', false);
        $this->assertDatabaseHas('donation_drive_histories', ['drive_id' => $drive->id, 'action' => 'reopened', 'user_id' => 1]);
    }

    public function test_documents_are_stored_privately_in_spaces_and_legacy_files_remain_available(): void
    {
        $this->documentTestRoot = storage_path('framework/testing/donation-drive-'.Str::uuid());
        $spaces = Storage::build(['driver' => 'local', 'root' => $this->documentTestRoot.'/spaces']);
        $local = Storage::build(['driver' => 'local', 'root' => $this->documentTestRoot.'/local']);
        Storage::set('spaces', $spaces);
        Storage::set('local', $local);
        $this->withoutMiddleware(RequireModulePermission::class);
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->id = 1;
        $admin->shouldReceive('isSuperAdmin')->andReturnTrue();
        Sanctum::actingAs($admin);
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        $file = UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 test document');

        $response = $this->postJson("/api/v1/donation-drive/admin/drives/{$drive->id}/documents", ['document' => $file])
            ->assertCreated()->assertJsonMissingPath('data.path')->assertJsonMissingPath('data.storage_disk');
        $document = $drive->documents()->findOrFail($response->json('data.id'));
        $this->assertSame('spaces', $document->storage_disk);
        $this->assertStringStartsWith("donation-drive/{$drive->id}/", $document->path);
        $this->assertTrue($spaces->exists($document->path));
        $this->assertSame('private', $spaces->getVisibility($document->path));

        $local->put('donation-drives/legacy.pdf', '%PDF-1.4 legacy');
        $legacy = $drive->documents()->create([
            'original_name' => 'legacy.pdf',
            'path' => 'donation-drives/legacy.pdf',
            'mime_type' => 'application/pdf',
            'size' => 15,
        ])->fresh();
        $this->assertSame('local', $legacy->storage_disk);
        foreach ([$document, $legacy] as $entry) {
            $url = URL::temporarySignedRoute('api.donation-drive.document.preview', now()->addMinutes(30), ['document' => $entry->id]);
            $this->assertStringStartsWith('%PDF-', $this->get($url)->assertOk()->streamedContent());
            $this->deleteJson("/api/v1/donation-drive/admin/documents/{$entry->id}")->assertOk();
        }
        $this->assertFalse($spaces->exists($document->path));
        $this->assertFalse($local->exists($legacy->path));
        $this->assertDatabaseCount('donation_drive_documents', 0);
    }

    public function test_failed_document_storage_does_not_create_a_record(): void
    {
        $this->withoutMiddleware(RequireModulePermission::class);
        $admin = new User();
        $admin->id = 1;
        Sanctum::actingAs($admin);
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('putFile')->once()->andReturn(false);
        Storage::set('spaces', $disk);

        $this->postJson("/api/v1/donation-drive/admin/drives/{$drive->id}/documents", [
            'document' => UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 test document'),
        ])->assertStatus(500)->assertJsonPath('message', 'Unable to store the document in Spaces.');
        $this->assertDatabaseCount('donation_drive_documents', 0);
    }

    public function test_failed_document_deletion_keeps_the_database_record(): void
    {
        $this->withoutMiddleware(RequireModulePermission::class);
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->id = 1;
        $admin->shouldReceive('isSuperAdmin')->andReturnTrue();
        Sanctum::actingAs($admin);
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        $document = $drive->documents()->create([
            'original_name' => 'brief.pdf', 'path' => 'donation-drive/1/brief.pdf',
            'storage_disk' => 'spaces', 'mime_type' => 'application/pdf', 'size' => 100,
        ]);
        $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('spaces', $disk);

        $this->deleteJson("/api/v1/donation-drive/admin/documents/{$document->id}")
            ->assertStatus(500)->assertJsonPath('message', 'Unable to delete the stored document.');
        $this->assertDatabaseHas('donation_drive_documents', ['id' => $document->id]);
        $this->assertDatabaseCount('donation_drive_histories', 0);
    }

    public function test_document_storage_migration_keeps_existing_documents_local(): void
    {
        Schema::table('donation_drive_documents', fn (Blueprint $table) => $table->dropColumn('storage_disk'));
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        $documentId = \Illuminate\Support\Facades\DB::table('donation_drive_documents')->insertGetId([
            'drive_id' => $drive->id, 'original_name' => 'legacy.pdf',
            'path' => 'donation-drives/legacy.pdf', 'size' => 100,
        ]);
        $migration = require base_path('Modules/DonationDrive/Database/migrations/2026_11_21_010000_add_storage_disk_to_donation_drive_documents.php');

        $migration->up();
        $this->assertDatabaseHas('donation_drive_documents', ['id' => $documentId, 'storage_disk' => 'local']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('donation_drive_documents', 'storage_disk'));
    }

    public function test_closure_requires_an_authenticated_administrator(): void
    {
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        $this->patchJson("/api/v1/donation-drive/admin/drives/{$drive->id}/closure", ['is_closed' => true])->assertUnauthorized();
        $this->assertFalse($drive->fresh()->is_closed);
    }

    public function test_closure_migration_preserves_existing_drives_as_open(): void
    {
        Schema::table('donation_drives', fn (Blueprint $table) => $table->dropColumn('is_closed'));
        $driveId = \Illuminate\Support\Facades\DB::table('donation_drives')->insertGetId(['title' => 'Existing', 'is_active' => true]);
        $migration = require base_path('Modules/DonationDrive/Database/migrations/2026_11_21_000000_add_closed_status_to_donation_drives.php');

        $migration->up();
        $this->assertDatabaseHas('donation_drives', ['id' => $driveId, 'is_active' => true, 'is_closed' => false]);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('donation_drives', 'is_closed'));
    }

    public function test_closed_drives_remain_visible_but_reject_new_and_changed_contributions(): void
    {
        $donor = DonationDonor::create(['first_name' => 'Maria', 'last_name' => 'Santos', 'normalized_name' => 'maria santos']);
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true, 'is_closed' => true]);
        $other = DonationDrive::create(['title' => 'Other', 'is_active' => true, 'is_closed' => true]);
        DonationDrive::create(['title' => 'Hidden', 'is_active' => false, 'is_closed' => true]);
        $donor->donations()->create(['drive_id' => $drive->id, 'amount' => 500, 'cutoff' => 1]);
        Sanctum::actingAs($donor, ['donation-drive'], 'donation');

        $this->getJson('/api/v1/donation-drive/drives')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $drive->id, 'title' => 'Relief'])
            ->assertJsonFragment(['is_closed' => true])
            ->assertJsonFragment(['amount' => '500.00']);
        $this->getJson('/api/v1/donation-drive/my-donations')->assertOk()->assertJsonCount(1, 'data');
        foreach ([$drive, $other] as $closedDrive) {
            $this->postJson("/api/v1/donation-drive/drives/{$closedDrive->id}/donations", ['amount' => 750, 'cutoff' => 2])
                ->assertForbidden()->assertJsonPath('message', 'This donation drive is closed to contributions.');
        }
        $this->assertDatabaseCount('donation_drive_donations', 1);
        $this->assertDatabaseHas('donation_drive_donations', ['drive_id' => $drive->id, 'amount' => 500, 'cutoff' => 1]);
        $this->assertDatabaseCount('donation_drive_donation_histories', 0);

        $drive->update(['is_closed' => false]);
        $this->postJson("/api/v1/donation-drive/drives/{$drive->id}/donations", ['amount' => 750, 'cutoff' => 2])->assertOk();
    }

    public function test_admin_donor_list_paginates_and_filters(): void
    {
        $this->withoutMiddleware([Authenticate::class, RequireModulePermission::class]);
        for ($i = 1; $i <= 6; $i++) {
            DonationDonor::create([
                'first_name' => "Donor{$i}",
                'last_name' => 'Example',
                'normalized_name' => "donor{$i} example",
                'email' => "donor{$i}@example.test",
                'email_verified_at' => now(),
            ]);
        }

        $this->getJson('/api/v1/donation-drive/admin/donors?page=1')
            ->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('total', 6)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.email', 'donor1@example.test');
        $this->getJson('/api/v1/donation-drive/admin/donors?page=2')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 6);
        $this->getJson('/api/v1/donation-drive/admin/donors?page=1&search=donor6')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 1);
    }

    public function test_removing_a_donor_requires_authentication(): void
    {
        $donor = DonationDonor::create([
            'first_name' => 'Empty', 'last_name' => 'Donor', 'normalized_name' => 'empty donor',
        ]);

        $this->deleteJson("/api/v1/donation-drive/admin/donors/{$donor->id}")->assertUnauthorized();
        $this->assertDatabaseHas('donation_donors', ['id' => $donor->id]);
    }

    public function test_admin_can_remove_only_donors_without_donations(): void
    {
        $this->withoutMiddleware([Authenticate::class, RequireModulePermission::class]);
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('isSuperAdmin')->andReturnTrue();
        Sanctum::actingAs($admin);
        $emptyDonor = DonationDonor::create([
            'first_name' => 'Empty', 'last_name' => 'Donor', 'normalized_name' => 'empty donor',
        ]);
        $emptyDonor->createToken('donation-drive');
        $donor = DonationDonor::create([
            'first_name' => 'Existing', 'last_name' => 'Donor', 'normalized_name' => 'existing donor',
        ]);
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        $donor->donations()->create(['drive_id' => $drive->id, 'amount' => 100, 'cutoff' => 1]);

        $this->deleteJson("/api/v1/donation-drive/admin/donors/{$donor->id}")->assertStatus(409);
        $this->assertDatabaseHas('donation_drive_donations', ['donor_id' => $donor->id]);
        $this->assertDatabaseHas('donation_donors', ['id' => $donor->id]);
        $this->deleteJson("/api/v1/donation-drive/admin/donors/{$emptyDonor->id}")->assertOk();
        $this->assertDatabaseMissing('donation_donors', ['id' => $emptyDonor->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => DonationDonor::class, 'tokenable_id' => $emptyDonor->id]);
    }

    public function test_donation_drive_admin_cannot_manage_donor_admin_access(): void
    {
        $this->withoutMiddleware([Authenticate::class, RequireModulePermission::class]);
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('isSuperAdmin')->andReturnFalse();
        Sanctum::actingAs($admin);
        $donor = DonationDonor::create([
            'first_name' => 'Review', 'last_name' => 'Only', 'normalized_name' => 'review only',
        ]);

        $this->patchJson("/api/v1/donation-drive/admin/donors/{$donor->id}/admin", ['is_admin' => true])
            ->assertForbidden();
        $this->deleteJson("/api/v1/donation-drive/admin/donors/{$donor->id}")
            ->assertForbidden();
        $this->assertFalse($donor->fresh()->is_admin);
    }

    public function test_donation_drive_admin_cannot_reactivate_or_reopen_a_drive(): void
    {
        $this->withoutMiddleware([Authenticate::class, RequireModulePermission::class]);
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->id = 1;
        $admin->shouldReceive('isSuperAdmin')->andReturnFalse();
        Sanctum::actingAs($admin);
        $inactive = DonationDrive::create(['title' => 'Draft', 'is_active' => false]);
        $closed = DonationDrive::create(['title' => 'Closed', 'is_active' => true, 'is_closed' => true]);

        $this->patchJson("/api/v1/donation-drive/admin/drives/{$inactive->id}/status", ['is_active' => true])
            ->assertForbidden();
        $this->patchJson("/api/v1/donation-drive/admin/drives/{$closed->id}/closure", ['is_closed' => false])
            ->assertForbidden();
        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertTrue($closed->fresh()->is_closed);
    }

    public function test_donor_admin_can_manage_drives_and_view_donors_without_employee_identity(): void
    {
        $this->documentTestRoot = storage_path('framework/testing/donation-drive-'.Str::uuid());
        $spaces = Storage::build(['driver' => 'local', 'root' => $this->documentTestRoot.'/spaces']);
        Storage::set('spaces', $spaces);
        $donor = DonationDonor::create([
            'first_name' => 'Donor', 'last_name' => 'Admin', 'normalized_name' => 'donor admin', 'is_admin' => true,
        ]);
        Sanctum::actingAs($donor, ['donation-drive'], 'donation');

        $driveId = $this->postJson('/api/v1/donation-drive/donor-admin/drives', [
            'title' => 'Community Relief', 'is_active' => true,
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('donation_drives', ['id' => $driveId, 'created_by' => null]);
        $this->assertDatabaseHas('donation_drive_histories', ['drive_id' => $driveId, 'user_id' => null, 'action' => 'created']);
        $this->assertSame($donor->id, DonationDrive::findOrFail($driveId)->histories()->first()->details['donor_admin']['id']);

        $this->postJson("/api/v1/donation-drive/donor-admin/drives/{$driveId}/documents", [
            'document' => UploadedFile::fake()->createWithContent('brief.pdf', '%PDF-1.4 test document'),
        ])->assertCreated()->assertJsonPath('data.uploaded_by', null);
        $this->getJson('/api/v1/donation-drive/donor-admin/donors?page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/donation-drive/donor-admin/drives')->assertOk()
            ->assertJsonPath('data.0.title', 'Community Relief')
            ->assertJsonPath('data.0.report_users', []);
        $this->patchJson("/api/v1/donation-drive/donor-admin/drives/{$driveId}/closure", ['is_closed' => true])->assertOk();
        $this->patchJson("/api/v1/donation-drive/donor-admin/drives/{$driveId}/closure", ['is_closed' => false])->assertForbidden();
        $this->patchJson("/api/v1/donation-drive/donor-admin/drives/{$driveId}/status", ['is_active' => false])->assertOk();
        $this->patchJson("/api/v1/donation-drive/donor-admin/drives/{$driveId}/status", ['is_active' => true])->assertForbidden();
        $this->getJson('/api/v1/donation-drive/donor-admin/drives')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_donor_management_requires_current_admin_access(): void
    {
        $donor = DonationDonor::create([
            'first_name' => 'Regular', 'last_name' => 'Donor', 'normalized_name' => 'regular donor',
        ]);
        $drive = DonationDrive::create(['title' => 'Relief', 'is_active' => true]);
        Sanctum::actingAs($donor, ['donation-drive'], 'donation');

        $this->getJson('/api/v1/donation-drive/donor-admin/drives')->assertForbidden();
        $this->getJson('/api/v1/donation-drive/donor-admin/donors?page=1')->assertForbidden();
        $this->postJson('/api/v1/donation-drive/donor-admin/drives', ['title' => 'Denied'])->assertForbidden();
        $this->patchJson("/api/v1/donation-drive/donor-admin/drives/{$drive->id}/status", ['is_active' => false])->assertForbidden();
        $this->patchJson("/api/v1/donation-drive/donor-admin/drives/{$drive->id}/closure", ['is_closed' => true])->assertForbidden();
        $this->postJson("/api/v1/donation-drive/donor-admin/drives/{$drive->id}/documents")->assertForbidden();
        $this->assertTrue($drive->fresh()->is_active);
    }

    public function test_donor_admin_token_loses_management_access_when_role_is_removed(): void
    {
        $donor = DonationDonor::create([
            'first_name' => 'Former', 'last_name' => 'Admin', 'normalized_name' => 'former admin', 'is_admin' => true,
        ]);
        $token = $donor->createToken('donation-drive', ['donation-drive'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/donation-drive/donor-admin/donors?page=1')->assertOk();
        $this->withToken($token)->patchJson("/api/v1/donation-drive/admin/donors/{$donor->id}/admin", ['is_admin' => true])->assertUnauthorized();
        $donor->update(['is_admin' => false]);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/donation-drive/donor-admin/donors?page=1')->assertForbidden();
    }

    public function test_donor_management_requires_authentication(): void
    {
        $this->getJson('/api/v1/donation-drive/donor-admin/donors?page=1')->assertUnauthorized();
        $this->postJson('/api/v1/donation-drive/donor-admin/drives', ['title' => 'Denied'])->assertUnauthorized();
    }
}
