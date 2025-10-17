<?php

namespace Modules\ClientMasterlist\App\Services;

use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * RecipientResolverService
 * 
 * Handles resolving recipients for notifications based on various criteria:
 * - Email addresses
 * - Enrollee IDs
 * - Status-based targeting
 * - Date range filtering
 */
class RecipientResolverService
{
    /**
     * Resolve recipients from input data
     * 
     * @param array $data Request data containing recipient information
     * @param Notification $notification The notification being sent
     * @return array Resolved recipients and metadata
     */
    public function resolveRecipients($data, $notification)
    {
        $to = $data['to'] ?? '';

        // Check if 'to' is a list of enrollee IDs
        if ($this->isIdList($to)) {
            return $this->resolveFromEnrolleeIds($to, $data);
        }

        // Check if we need to resolve by enrollee status
        if (isset($data['enrollee_status']) && !empty($data['enrollee_status'])) {
            return $this->resolveByEnrolleeStatus($data, $notification);
        }

        // Standard email resolution
        return $this->resolveFromEmails($data);
    }

    /**
     * Check if the string is a comma-separated list of IDs
     */
    public function isIdList($string)
    {
        return preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', $string);
    }

    /**
     * Resolve recipients from enrollee IDs
     */
    private function resolveFromEnrolleeIds($idString, $data)
    {
        $ids = array_filter(
            array_map('trim', explode(',', $idString)),
            function ($id) {
                return is_numeric($id);
            }
        );

        if (empty($ids)) {
            return [
                'success' => false,
                'message' => 'No valid enrollee IDs provided.',
                'recipients' => []
            ];
        }

        $recipients = [];
        $results = [];

        foreach ($ids as $enrolleeId) {
            $enrollee = Enrollee::find($enrolleeId);

            if (!$enrollee || empty($enrollee->email1)) {
                $results[] = [
                    'enrollee_id' => $enrolleeId,
                    'success' => false,
                    'message' => 'Enrollee not found or missing email.'
                ];
                continue;
            }

            $recipients[] = [
                'email' => $enrollee->email1,
                'enrollee_id' => $enrolleeId,
                'enrollee' => $enrollee
            ];

            $results[] = [
                'enrollee_id' => $enrolleeId,
                'success' => true,
                'email' => $enrollee->email1
            ];
        }

        return [
            'success' => true,
            'recipients' => $recipients,
            'results' => $results,
            'type' => 'enrollee_ids'
        ];
    }

    /**
     * Resolve recipients by enrollee status with optional date filtering
     */
    private function resolveByEnrolleeStatus($data, $notification)
    {
        $enrolleeStatus = $data['enrollee_status'];
        $notificationId = $data['notification_id'] ?? null;

        if (!$notificationId || !$notification || !isset($notification->enrollment_id)) {
            return [
                'success' => false,
                'message' => 'Notification or enrollment not found for status filter.',
                'recipients' => []
            ];
        }

        $enrollmentId = $notification->enrollment_id;

        // Build query for enrollees with the given status
        $enrolleeQuery = Enrollee::where('enrollment_id', $enrollmentId)
            ->where('enrollment_status', $enrolleeStatus)
            ->whereNull('deleted_at');

        // Apply date filtering if provided
        if (!empty($data['dateFrom'])) {
            $enrolleeQuery->whereDate('updated_at', '>=', $data['dateFrom'] . ' 00:00:00');
        }

        if (!empty($data['dateTo'])) {
            $enrolleeQuery->whereDate('updated_at', '<=', $data['dateTo'] . ' 23:59:59');
        }

        $enrollees = $enrolleeQuery->get();

        if ($enrollees->count() === 0) {
            return [
                'success' => false,
                'message' => 'No enrollees found with status: ' . $enrolleeStatus,
                'recipients' => []
            ];
        }

        $recipients = [];
        foreach ($enrollees as $enrollee) {
            if (!empty($enrollee->email1)) {
                $recipients[] = [
                    'email' => $enrollee->email1,
                    'enrollee_id' => $enrollee->id,
                    'enrollee' => $enrollee
                ];
            }
        }

        return [
            'success' => true,
            'recipients' => $recipients,
            'enrollee_ids' => $enrollees->pluck('id')->toArray(),
            'type' => 'status_based'
        ];
    }

    /**
     * Resolve recipients from email addresses
     */
    private function resolveFromEmails($data)
    {
        $to = $this->validateAndCleanEmails($data['to'] ?? '');
        $cc = $this->validateAndCleanEmails($data['cc'] ?? '');
        $bcc = $this->validateAndCleanEmails($data['bcc'] ?? '');

        if (empty($to)) {
            return [
                'success' => false,
                'message' => 'No valid recipient email addresses provided after filtering.',
                'recipients' => []
            ];
        }

        return [
            'success' => true,
            'recipients' => [
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc
            ],
            'type' => 'email_addresses',
            'enrollee_id' => $data['attach_enrollee_id'] ?? $data['enrollee_id'] ?? null
        ];
    }

    /**
     * Get enrollees by status and date range for scheduled notifications
     */
    public function getEnrolleesByStatus($enrollmentId, $status, $dateRange)
    {
        if (!$enrollmentId) {
            return [];
        }

        $enrollees = Enrollee::where('enrollment_id', $enrollmentId)
            ->where('enrollment_status', $status)
            ->where('updated_at', '>=', $dateRange['from'])
            ->where('updated_at', '<=', $dateRange['to'])
            ->whereNull('deleted_at')
            ->get();

        if ($enrollees->count() > 0) {
            return $enrollees->pluck('id')->toArray();
        }

        Log::info("No {$status} enrollees found for enrollment {$enrollmentId} in date range", [
            'enrollment_id' => $enrollmentId,
            'status' => $status,
            'date_from' => $dateRange['from'],
            'date_to' => $dateRange['to']
        ]);

        return [];
    }

    /**
     * Validate and clean email addresses
     */
    private function validateAndCleanEmails($emailString)
    {
        if (empty($emailString)) {
            return [];
        }

        $emails = array_filter(
            array_map('trim', explode(',', rtrim(trim($emailString), ','))),
            function ($email) {
                return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
            }
        );

        return $emails;
    }

    /**
     * Prepare recipients for sending
     * 
     * @param array $resolvedRecipients Output from resolveRecipients
     * @param bool $sendAsMultiple Whether to send as multiple individual emails
     * @return array Prepared recipient data for email sending
     */
    public function prepareForSending($resolvedRecipients, $sendAsMultiple = false)
    {
        if (!$resolvedRecipients['success']) {
            return $resolvedRecipients;
        }

        $type = $resolvedRecipients['type'];
        $recipients = $resolvedRecipients['recipients'];

        switch ($type) {
            case 'enrollee_ids':
                // For enrollee IDs, always send individual emails
                return [
                    'success' => true,
                    'send_type' => 'individual',
                    'recipients' => $recipients
                ];

            case 'status_based':
                // For status-based, send individual emails to each enrollee
                return [
                    'success' => true,
                    'send_type' => 'individual',
                    'recipients' => $recipients
                ];

            case 'email_addresses':
                if ($sendAsMultiple) {
                    // Convert to individual recipients
                    $individualRecipients = [];
                    foreach ($recipients['to'] as $email) {
                        $individualRecipients[] = [
                            'to' => [$email],
                            'cc' => $recipients['cc'],
                            'bcc' => $recipients['bcc'],
                            'enrollee_id' => $resolvedRecipients['enrollee_id']
                        ];
                    }

                    return [
                        'success' => true,
                        'send_type' => 'individual',
                        'recipients' => $individualRecipients
                    ];
                } else {
                    // Send as single email to all recipients
                    return [
                        'success' => true,
                        'send_type' => 'bulk',
                        'recipients' => [$recipients],
                        'enrollee_id' => $resolvedRecipients['enrollee_id']
                    ];
                }

            default:
                return [
                    'success' => false,
                    'message' => 'Unknown recipient type: ' . $type
                ];
        }
    }
}
