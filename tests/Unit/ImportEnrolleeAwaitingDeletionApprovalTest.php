<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Modules\ClientMasterlist\App\Http\Controllers\ImportEnrolleeController;
use Tests\TestCase;

class ImportEnrolleeAwaitingDeletionApprovalTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_future_employee_end_date_is_not_moved_to_awaiting_deletion_approval(): void
    {
        Carbon::setTestNow('2026-11-27');

        $enrolleeData = [
            'employee_id' => 'EMP-001',
            'employee_end_date' => '2026-11-28',
        ];
        $healthInsuranceData = [];

        $this->applyAwaitingDeletionApprovalState($enrolleeData, $healthInsuranceData);

        $this->assertSame([
            'employee_id' => 'EMP-001',
            'employee_end_date' => '2026-11-28',
        ], $enrolleeData);
        $this->assertSame([], $healthInsuranceData);
    }

    public function test_today_employee_end_date_is_moved_to_awaiting_deletion_approval(): void
    {
        Carbon::setTestNow('2026-11-27');

        $enrolleeData = [
            'employee_id' => 'EMP-002',
            'employment_end_date' => '2026-11-27',
            'enrollment_status' => 'APPROVED',
        ];
        $healthInsuranceData = [
            'coverage_end_date' => '2026-11-27',
        ];

        $this->applyAwaitingDeletionApprovalState($enrolleeData, $healthInsuranceData);

        $this->assertSame('2026-11-27', $enrolleeData['temp_end_date']);
        $this->assertSame('AWAITING-DELETION-APPROVAL', $enrolleeData['enrollment_status']);
        $this->assertSame('ACTIVE', $enrolleeData['status']);
        $this->assertArrayNotHasKey('employment_end_date', $enrolleeData);
        $this->assertArrayNotHasKey('coverage_end_date', $healthInsuranceData);
    }

    private function applyAwaitingDeletionApprovalState(array &$enrolleeData, array &$healthInsuranceData): void
    {
        $controller = new ImportEnrolleeController();
        $method = new \ReflectionMethod($controller, 'applyAwaitingDeletionApprovalState');
        $method->setAccessible(true);
        $method->invokeArgs($controller, [&$enrolleeData, &$healthInsuranceData, 'ymd']);
    }
}
