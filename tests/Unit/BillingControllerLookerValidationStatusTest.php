<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Modules\ClientMasterlist\App\Http\Controllers\BillingController;
use Tests\TestCase;

class BillingControllerLookerValidationStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('cm_health_insurance');
        Schema::dropIfExists('cm_dependent');
        Schema::dropIfExists('cm_principal');

        Schema::create('cm_principal', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('enrollment_id');
            $table->string('employee_id')->nullable();
            $table->string('member_id')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('enrollment_status')->nullable();
            $table->dateTime('submission_date')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('cm_health_insurance', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('principal_id')->nullable();
            $table->unsignedInteger('dependent_id')->nullable();
            $table->date('coverage_start_date')->nullable();
            $table->date('coverage_end_date')->nullable();
            $table->string('certificate_number')->nullable();
        });

        Schema::create('cm_dependent', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('principal_id');
            $table->string('employee_id')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('enrollment_status')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_modal_billing_dependent_pending_uses_principal_approved_status(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 301,
            'enrollment_id' => 99,
            'employee_id' => 'principal-301',
            'member_id' => null,
            'enrollment_status' => 'APPROVED',
            'submission_date' => Carbon::now()->subDays(3),
            'deleted_at' => null,
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        DB::table('cm_dependent')->insert([
            'id' => 401,
            'principal_id' => 301,
            'employee_id' => 'mwp6dg9',
            'birth_date' => '1970-02-13',
            'enrollment_status' => 'FOR-APPROVAL',
            'deleted_at' => null,
            'created_at' => Carbon::now()->subDays(2),
            'updated_at' => Carbon::now()->subDays(2),
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 501,
            'principal_id' => null,
            'dependent_id' => 401,
            'coverage_start_date' => '2026-11-01',
            'coverage_end_date' => null,
            'certificate_number' => '1168011146916708',
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveModalBillingStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_NO' => 'mwp6dg9',
                'BIRTH_DATE' => '1970-02-13',
            ],
            'EMPLOYEE_NO',
            'BIRTH_DATE',
            11,
            2026
        );

        $this->assertSame('ACTIVE', $result['row_data']['SYSTEM_STATUS']);
        $this->assertSame('1168011146916708', $result['row_data']['ACCOUNT_NO']);
        $this->assertSame('2026-11-01', $result['row_data']['COVERAGE_START_DATE']);
        $this->assertNull($result['row_data']['COVERAGE_END_DATE']);
    }

    public function test_looker_validation_marks_approved_without_end_date_as_active_and_matched(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 101,
            'enrollment_id' => 99,
            'employee_id' => 'm7rxvrp',
            'member_id' => null,
            'enrollment_status' => 'APPROVED',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 201,
            'principal_id' => 101,
            'coverage_start_date' => '2025-12-01',
            'coverage_end_date' => null,
            'certificate_number' => '1168011146811925',
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => 'm7rxvrp',
                'STATUS' => 'ENROLLED',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('ACTIVE', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
        $this->assertSame('2025-12-01', $result['row_data']['COVERAGE_START_DATE']);
        $this->assertNull($result['row_data']['COVERAGE_END_DATE']);
    }

    public function test_looker_validation_marks_awaiting_deletion_approval_without_end_date_as_active_and_matched(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 111,
            'enrollment_id' => 99,
            'employee_id' => 'awaiting-delete-approval',
            'member_id' => null,
            'enrollment_status' => 'AWAITING-DELETION-APPROVAL',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 211,
            'principal_id' => 111,
            'coverage_start_date' => '2025-12-01',
            'coverage_end_date' => null,
            'certificate_number' => 'CERT-111',
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => 'awaiting-delete-approval',
                'STATUS' => 'ENROLLED',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('ACTIVE', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
    }

    public function test_looker_validation_keeps_awaiting_deletion_approval_active_even_with_past_end_date(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 112,
            'enrollment_id' => 99,
            'employee_id' => '35knree',
            'member_id' => null,
            'enrollment_status' => 'AWAITING-DELETION-APPROVAL',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 212,
            'principal_id' => 112,
            'coverage_start_date' => '2025-10-01',
            'coverage_end_date' => '2025-10-31',
            'certificate_number' => 'CERT-112',
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => '35knree',
                'STATUS' => 'ENROLLED',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('ACTIVE', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
    }

    public function test_looker_validation_maps_resigned_with_certificate_to_terminated(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 102,
            'enrollment_id' => 99,
            'employee_id' => 'resigned-cert',
            'member_id' => null,
            'enrollment_status' => 'RESIGNED',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 202,
            'principal_id' => 102,
            'coverage_start_date' => '2025-11-01',
            'coverage_end_date' => '2025-11-30',
            'certificate_number' => 'CERT-1002',
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => 'resigned-cert',
                'STATUS' => 'TERMINATED',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('TERMINATED', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
    }

    public function test_looker_validation_treats_not_enrolled_as_match_for_terminated(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 105,
            'enrollment_id' => 99,
            'employee_id' => 'not-enrolled-terminated',
            'member_id' => null,
            'enrollment_status' => 'RESIGNED',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 205,
            'principal_id' => 105,
            'coverage_start_date' => '2025-11-01',
            'coverage_end_date' => '2025-11-30',
            'certificate_number' => 'CERT-1005',
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => 'not-enrolled-terminated',
                'STATUS' => 'NOT_ENROLLED',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('TERMINATED', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
    }

    public function test_looker_validation_maps_resigned_without_certificate_to_inactive_did_not_take_action(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 103,
            'enrollment_id' => 99,
            'employee_id' => 'resigned-no-cert',
            'member_id' => null,
            'enrollment_status' => 'RESIGNED',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 203,
            'principal_id' => 103,
            'coverage_start_date' => '2025-11-01',
            'coverage_end_date' => null,
            'certificate_number' => null,
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => 'resigned-no-cert',
                'STATUS' => 'INACTIVE - DID NOT TAKE ACTION',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('INACTIVE - DID NOT TAKE ACTION', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
    }

    public function test_looker_validation_treats_not_enrolled_as_match_for_inactive_did_not_take_action(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 106,
            'enrollment_id' => 99,
            'employee_id' => 'not-enrolled-inactive-dnta',
            'member_id' => null,
            'enrollment_status' => 'RESIGNED',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 206,
            'principal_id' => 106,
            'coverage_start_date' => '2025-11-01',
            'coverage_end_date' => null,
            'certificate_number' => null,
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => 'not-enrolled-inactive-dnta',
                'STATUS' => 'NOT_ENROLLED',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('INACTIVE - DID NOT TAKE ACTION', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
    }

    public function test_looker_not_enrolled_matches_terminated_inactive_and_inactive_did_not_take_action(): void
    {
        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'isLookerStatusMatched');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller, 'TERMINATED', 'NOT_ENROLLED'));
        $this->assertTrue($method->invoke($controller, 'INACTIVE', 'NOT_ENROLLED'));
        $this->assertTrue($method->invoke($controller, 'INACTIVE - DID NOT TAKE ACTION', 'NOT_ENROLLED'));
    }

    public function test_looker_validation_maps_opted_out_to_waived(): void
    {
        DB::table('cm_principal')->insert([
            'id' => 104,
            'enrollment_id' => 99,
            'employee_id' => 'opted-out-member',
            'member_id' => null,
            'enrollment_status' => 'OPTED-OUT',
            'deleted_at' => null,
        ]);

        DB::table('cm_health_insurance')->insert([
            'id' => 204,
            'principal_id' => 104,
            'coverage_start_date' => '2025-11-01',
            'coverage_end_date' => null,
            'certificate_number' => null,
        ]);

        $controller = new BillingController();
        $method = new \ReflectionMethod($controller, 'resolveLookerFileValidationStatus');
        $method->setAccessible(true);

        $result = $method->invoke(
            $controller,
            99,
            [
                'EMPLOYEE_ID' => 'opted-out-member',
                'STATUS' => 'WAIVED',
            ],
            'EMPLOYEE_ID',
            'STATUS',
            'looker_file_validation'
        );

        $this->assertSame('WAIVED', $result['row_data']['SYSTEM_STATUS']);
        $this->assertTrue($result['row_data']['MATCHED']);
    }

    public function test_pending_employee_days_are_counted_from_created_at(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0));

        try {
            $controller = new BillingController();
            $method = new \ReflectionMethod($controller, 'buildModalReconReportRowData');
            $method->setAccessible(true);

            $result = $method->invoke($controller, [
                'SYSTEM_STATUS' => 'PENDING_EMPLOYEE',
                'CREATED_AT' => '2026-11-01',
                'SUBMISSION_DATE' => '2026-11-09',
            ]);

            $this->assertSame(10, $result['DAY(S)']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pending_hmo_days_are_counted_from_submission_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0));

        try {
            $controller = new BillingController();
            $method = new \ReflectionMethod($controller, 'buildModalReconReportRowData');
            $method->setAccessible(true);

            $result = $method->invoke($controller, [
                'SYSTEM_STATUS' => 'PENDING_HMO',
                'CREATED_AT' => '2026-10-01',
                'SUBMISSION_DATE' => '2026-11-07',
            ]);

            $this->assertSame(4, $result['DAY(S)']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
