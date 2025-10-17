<?php

namespace Modules\ClientMasterlist\App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Http\Controllers\ExportEnrolleesController;

/**
 * AttachmentHandlerService
 * 
 * Handles file attachments and CSV generation for notifications.
 * Manages temporary files, CSV exports, and cleanup operations.
 */
class AttachmentHandlerService
{
    /**
     * Generate CSV attachment using ExportEnrolleesController
     * 
     * @param int $enrollmentId The enrollment ID
     * @param string|null $enrollmentStatus Filter by enrollment status
     * @param bool $withDependents Include dependents in export
     * @param bool $isRenewal Whether this is a renewal enrollment
     * @param string|null $dateFrom Start date for filtering
     * @param string|null $dateTo End date for filtering
     * @param array $columns Columns to include in export
     * @return array|null CSV attachment data or null on failure
     */
    public function generateCsvAttachment(
        $enrollmentId,
        $enrollmentStatus = null,
        $withDependents = false,
        $isRenewal = false,
        $dateFrom = null,
        $dateTo = null,
        $columns = [],
        $exportEnrollmentType = null
    ) {
        try {
            // Create request object with parameters
            $request = new Request([
                'enrollment_id' => $enrollmentId,
                'enrollment_status' => $enrollmentStatus,
                'with_dependents' => $withDependents,
                'is_renewal' => $isRenewal,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'columns' => $columns,
                'export_enrollment_type' => $exportEnrollmentType
            ]);

            // Create ExportEnrolleesController instance and call export method
            $exportController = new ExportEnrolleesController();
            $response = $exportController->exportEnrolleesForAttachment($request);

            // Get CSV content from response
            $csvContent = $response->getContent();

            // Count data rows (excluding header)
            $dataRows = $this->countCsvDataRows($csvContent);

            // Generate temporary file
            $filename = $this->generateCsvFilename($enrollmentStatus);
            $tempPaths = $this->createTempCsvFile($csvContent);

            Log::info("CSV attachment generated", [
                'enrollment_id' => $enrollmentId,
                'enrollment_status' => $enrollmentStatus,
                'filename' => $filename,
                'data_rows' => $dataRows,
                'has_data' => $dataRows > 0
            ]);

            return [
                'path' => $tempPaths['csv'],
                'name' => $filename,
                'temp_path' => $tempPaths['temp'],
                'has_data' => $dataRows > 0,
                'data_rows' => $dataRows
            ];
        } catch (\Exception $e) {
            Log::error("Failed to generate CSV attachment", [
                'enrollment_id' => $enrollmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Count the number of data rows in CSV content (excluding header)
     */
    private function countCsvDataRows($csvContent)
    {
        $csvLines = explode("\n", trim($csvContent));

        // Filter out empty lines and subtract 1 for header
        $nonEmptyLines = array_filter($csvLines, function ($line) {
            return trim($line) !== '';
        });

        return max(0, count($nonEmptyLines) - 1);
    }

    /**
     * Generate CSV filename based on status and timestamp
     */
    private function generateCsvFilename($enrollmentStatus = null)
    {
        $status = $enrollmentStatus ?: 'ALL';
        $timestamp = date('Ymd_His');

        return "ENROLLEES_{$status}_{$timestamp}.csv";
    }

    /**
     * Create temporary CSV file and return paths
     */
    private function createTempCsvFile($csvContent)
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'csv_attachment_');
        $tempCsvPath = $tempPath . '.csv';

        file_put_contents($tempCsvPath, $csvContent);

        return [
            'temp' => $tempPath,
            'csv' => $tempCsvPath
        ];
    }

    /**
     * Clean up temporary CSV files
     */
    public function cleanupCsvAttachment($csvAttachment)
    {
        if (!$csvAttachment) {
            return;
        }

        // Clean up main CSV file
        if (isset($csvAttachment['path']) && file_exists($csvAttachment['path'])) {
            @unlink($csvAttachment['path']);
            Log::debug("Cleaned up CSV file", ['path' => $csvAttachment['path']]);
        }

        // Clean up temporary file
        if (isset($csvAttachment['temp_path']) && file_exists($csvAttachment['temp_path'])) {
            @unlink($csvAttachment['temp_path']);
            Log::debug("Cleaned up temp file", ['path' => $csvAttachment['temp_path']]);
        }
    }

    /**
     * Validate CSV attachment has data before sending
     */
    public function validateCsvAttachment($csvAttachment)
    {
        if (!$csvAttachment) {
            return [
                'valid' => false,
                'reason' => 'No CSV attachment provided'
            ];
        }

        if (!isset($csvAttachment['has_data']) || !$csvAttachment['has_data']) {
            return [
                'valid' => false,
                'reason' => 'CSV attachment has no data rows',
                'data_rows' => $csvAttachment['data_rows'] ?? 0
            ];
        }

        if (!isset($csvAttachment['path']) || !file_exists($csvAttachment['path'])) {
            return [
                'valid' => false,
                'reason' => 'CSV file does not exist'
            ];
        }

        return [
            'valid' => true,
            'data_rows' => $csvAttachment['data_rows'] ?? 0
        ];
    }

    /**
     * Get CSV generation configuration for notification types
     */
    public function getCsvConfigForNotificationType($notificationType, $enrollmentId, $notification = null)
    {
        switch ($notificationType) {
            case 'REPORT: ATTACHMENT (SUBMITTED)':
                $schedulerService = new \Modules\ClientMasterlist\App\Services\SchedulerService();
                $dateRange = $schedulerService->calculateDateRangeFromSchedule($notification);
                return [
                    'type' => 'csv_generation',
                    'enrollment_id' => $enrollmentId,
                    'enrollment_status' => 'SUBMITTED',
                    'export_enrollment_type' => 'REGULAR',  // Add this to ensure is_renewal = false
                    'is_renewal' => false,
                    'with_dependents' => true,
                    'date_from' => $dateRange['from'],
                    'date_to' => $dateRange['to'],
                    'columns' => [
                        'employee_id',
                        'first_name',
                        'last_name',
                        'middle_name',
                        'birth_date',
                        'gender',
                        'email1',
                        'phone1',
                        'department',
                        'position',
                        'enrollment_status',
                        'relation'
                    ]
                ];

            case 'REPORT: ATTACHMENT (APPROVED)':
                $schedulerService = new \Modules\ClientMasterlist\App\Services\SchedulerService();
                $dateRange = $schedulerService->calculateDateRangeFromSchedule($notification);
                return [
                    'type' => 'csv_generation',
                    'enrollment_id' => $enrollmentId,
                    'enrollment_status' => 'APPROVED',
                    'export_enrollment_type' => 'REGULAR',  // Add this to ensure is_renewal = false
                    'is_renewal' => false,
                    'with_dependents' => true,
                    'date_from' => $dateRange['from'],
                    'date_to' => $dateRange['to'],
                    'columns' => [
                        'employee_id',
                        'first_name',
                        'last_name',
                        'middle_name',
                        'birth_date',
                        'gender',
                        'email1',
                        'phone1',
                        'department',
                        'position',
                        'enrollment_status',
                        'certificate_number',
                        'relation'
                    ]
                ];

            default:
                return null;
        }
    }

    /**
     * Process CSV generation request for scheduled notifications
     */
    public function processCsvForScheduledNotification($config)
    {
        if (!$config || $config['type'] !== 'csv_generation') {
            return null;
        }

        $csvAttachment = $this->generateCsvAttachment(
            $config['enrollment_id'],
            $config['enrollment_status'] ?? null,
            $config['with_dependents'] ?? false,
            $config['is_renewal'] ?? false,
            $config['date_from'] ?? null,
            $config['date_to'] ?? null,
            $config['columns'] ?? [],
            $config['export_enrollment_type'] ?? null
        );

        if (!$csvAttachment) {
            Log::warning("Failed to generate CSV attachment for scheduled notification", [
                'config' => $config
            ]);
            return null;
        }

        $validation = $this->validateCsvAttachment($csvAttachment);

        if (!$validation['valid']) {
            Log::info("CSV attachment validation failed", [
                'reason' => $validation['reason'],
                'data_rows' => $validation['data_rows'] ?? 0,
                'config' => $config
            ]);

            // Clean up empty/invalid CSV
            $this->cleanupCsvAttachment($csvAttachment);
            return null;
        }

        Log::info("CSV attachment validated successfully", [
            'filename' => $csvAttachment['name'],
            'data_rows' => $validation['data_rows']
        ]);

        return $csvAttachment;
    }

    /**
     * Create attachment array for email sending
     */
    public function prepareAttachmentsForEmail($csvAttachment = null, $additionalAttachments = [])
    {
        $attachments = [];

        // Add CSV attachment if present
        if ($csvAttachment) {
            $attachments['csv'] = $csvAttachment;
        }

        // Add additional attachments
        foreach ($additionalAttachments as $attachment) {
            $attachments[] = $attachment;
        }

        return $attachments;
    }
}
