<?php

namespace Tests\Unit;

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
        Schema::dropIfExists('cm_principal');

        Schema::create('cm_principal', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('enrollment_id');
            $table->string('employee_id')->nullable();
            $table->string('member_id')->nullable();
            $table->string('enrollment_status')->nullable();
            $table->softDeletes();
        });

        Schema::create('cm_health_insurance', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('principal_id')->nullable();
            $table->date('coverage_start_date')->nullable();
            $table->date('coverage_end_date')->nullable();
            $table->string('certificate_number')->nullable();
        });
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
}
