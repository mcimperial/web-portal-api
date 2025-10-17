<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Services\NotificationService;
use Modules\ClientMasterlist\App\Services\RecipientResolverService;
use Modules\ClientMasterlist\App\Services\VariableReplacementService;
use Modules\ClientMasterlist\App\Services\AttachmentHandlerService;
use Modules\ClientMasterlist\App\Services\SchedulerService;
use Modules\ClientMasterlist\App\Models\Enrollee;

/**
 * SendNotificationController (Refactored)
 * 
 * Simplified controller that orchestrates notification sending using dedicated service classes.
 * 
 * Features:
 * - Manual and scheduled notification sending
 * - Multiple recipient targeting strategies (emails, enrollee IDs, status-based)
 * - CSV report generation and attachment
 * - Variable replacement in email content
 * - Support for multiple email providers (Laravel Mail, Infobip)
 * 
 * The controller now uses the following service classes:
 * - NotificationService: Handles email sending
 * - RecipientResolverService: Resolves and validates recipients
 * - VariableReplacementService: Handles template variables and table generation
 * - AttachmentHandlerService: Manages CSV generation and file attachments
 * - SchedulerService: Handles cron scheduling and date calculations
 */
class SendNotificationController extends Controller
{
    private $notificationService;
    private $recipientResolver;
    private $variableReplacer;
    private $attachmentHandler;
    private $scheduler;

    public function __construct()
    {
        $this->notificationService = new NotificationService();
        $this->recipientResolver = new RecipientResolverService();
        $this->variableReplacer = new VariableReplacementService();
        $this->attachmentHandler = new AttachmentHandlerService();
        $this->scheduler = new SchedulerService();
    }
    /**
     * Handle sending a notification email.
     */
    public function send(Request $request)
    {
        Log::info("Send method called", [
            'request_data' => $request->all(),
            'has_csv_attachment' => $request->has('csv_attachment')
        ]);

        $enrolleeStatus = $request->input('enrollee_status');

        // If enrollee_status is provided, find all enrollees with that status for this enrollment
        if ($enrolleeStatus && $request->has('notification_id')) {
            $notificationId = $request->input('notification_id');
            $notification = Notification::find($notificationId);

            if ($notification && isset($notification->enrollment_id)) {
                $enrollmentId = $notification->enrollment_id;

                // Find enrollees with the given status and enrollment_id
                $enrolleeQuery = \Modules\ClientMasterlist\App\Models\Enrollee::where('enrollment_id', $enrollmentId)
                    ->where('enrollment_status', $enrolleeStatus)
                    ->whereNull('deleted_at');

                // Apply date filtering if provided
                if ($request->has('dateFrom') && $request->filled('dateFrom')) {
                    $enrolleeQuery->where('updated_at', '>=', $request->input('dateFrom') . ' 00:00:00');
                }

                if ($request->has('dateTo') && $request->filled('dateTo')) {
                    $enrolleeQuery->where('updated_at', '<=', $request->input('dateTo') . ' 23:59:59');
                }

                $enrollees = $enrolleeQuery->get();

                if ($enrollees->count() > 0) {
                    // Override the 'to' field with enrollee IDs
                    $enrolleeIds = $enrollees->pluck('id')->toArray();
                    $request->merge(['to' => implode(',', $enrolleeIds)]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => "No enrollees found with status '{$enrolleeStatus}' for the specified enrollment and date range.",
                    ], 404);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found or missing enrollment_id.',
                ], 404);
            }
        }

        // Validate request data
        $data = $request->validate([
            'notification_id' => 'required|integer|exists:cm_notification,id',
            'to' => 'required|string',
            'cc' => 'nullable|string',
            'bcc' => 'nullable|string',
            'attach_enrollee_id' => 'sometimes|nullable|integer',
            'use_saved' => 'sometimes|boolean',
            'send_as_multiple' => 'sometimes|boolean',
            'enrollee_id' => 'sometimes|nullable|integer',
            'dateFrom' => 'sometimes|nullable|date',
            'dateTo' => 'sometimes|nullable|date|after_or_equal:dateFrom',
        ]);

        // Find notification
        $notification = Notification::find($data['notification_id']);

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        Log::info("Notification found, proceeding with send logic", [
            'notification_id' => $notification->id,
            'notification_type' => $notification->notification_type,
            'to_value' => $data['to']
        ]);

        try {
            // If 'to' is a list of enrollee IDs, handle in a separate function
            if ($this->isIdList($data['to'])) {
                return $this->sendToEnrolleeIds($data, $notification);
            }

            // If use_saved is true, ignore 'to' and 'cc' from request and use saved values
            $data['enrollee_id'] = $data['attach_enrollee_id'] ?? ($data['enrollee_id'] ?? null);

            // If send_as_multiple is set and there are multiple emails, handle in a separate function
            if (!empty($data['send_as_multiple'])) {
                return $this->sendToMultipleEmails($data, $notification);
            }

            // Add CSV attachment to data if present in request
            if ($request->has('csv_attachment')) {
                $data['csv_attachment'] = $request->get('csv_attachment');
            }

            // Otherwise, send as a single email (default)
            return $this->sendSingleEmail($data, $notification);
        } catch (\Exception $e) {
            Log::error("Exception in send method", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if the 'to' string is a comma-separated list of IDs
     */
    private function isIdList($toRaw)
    {
        return preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', $toRaw);
    }

    /**
     * Send notification to each enrollee ID in the list
     */
    private function sendToEnrolleeIds($data, $notification)
    {
        $toRaw = $data['to'];
        $ids = array_filter(array_map('trim', explode(',', $toRaw)), function ($id) {
            return is_numeric($id);
        });
        if (empty($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid enrollee IDs provided.',
            ], 422);
        }
        $results = [];
        foreach ($ids as $enrolleeId) {
            $enrollee = \Modules\ClientMasterlist\App\Models\Enrollee::find($enrolleeId);
            if (!$enrollee || empty($enrollee->email1)) {
                $results[] = [
                    'enrollee_id' => $enrolleeId,
                    'success' => false,
                    'message' => 'Enrollee not found or missing email'
                ];
                continue;
            }
            $singleData = $data;
            $singleData['to'] = $enrollee->email1;
            $singleData['cc'] = $data['cc'] ?? null;
            $singleData['bcc'] = $data['bcc'] ?? null;
            $singleData['enrollee_id'] = $enrolleeId;

            $result = $this->sendSingleEmail($singleData, $notification);
            $responseData = $result->getData(true);

            $results[] = [
                'enrollee_id' => $enrolleeId,
                'success' => $responseData['success'] ?? false,
                'message' => $responseData['message'] ?? 'Unknown response'
            ];
        }
        return response()->json([
            'success' => true,
            'message' => 'Notifications sent to multiple enrollees.',
            'results' => $results
        ]);
    }

    /**
     * Send notification to each email in a comma-separated list (manual mode, send_as_multiple)
     * In multiple mode: send one email per recipient (each gets their own email, only their address in TO)
     */
    private function sendToMultipleEmails($data, $notification)
    {
        $toRaw = rtrim(trim($data['to']), ',');
        $to = array_filter(array_map('trim', explode(',', $toRaw)), function ($email) {
            return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
        });

        if (empty($to)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid recipient email addresses provided after filtering.',
            ], 422);
        }
        $results = [];
        foreach ($to as $email) {
            $singleData = $data;
            $singleData['to'] = $email;
            $singleData['cc'] = $data['cc'] ?? null;
            $singleData['bcc'] = $data['bcc'] ?? null;
            unset($singleData['send_as_multiple']);
            $response = $this->sendSingleEmail($singleData, $notification);
            $responseData = $response->getData(true);
            $results[] = [
                'to' => $email,
                'success' => $responseData['success'] ?? false,
                'message' => $responseData['message'] ?? 'Unknown response'
            ];
        }
        return response()->json([
            'success' => true,
            'message' => 'Notifications sent to multiple recipients.',
            'results' => $results
        ]);
    }

    /**
     * Send a single notification email (default path)
     * In single mode: send one email to all recipients in the TO array (everyone sees all addresses)
     */
    private function sendSingleEmail($data, $notification)
    {
        // Use the existing services but maintain compatibility with the original logic
        try {
            // Parse recipients
            $toRaw = rtrim(trim($data['to']), ',');
            $to = array_filter(array_map('trim', explode(',', $toRaw)), function ($email) {
                return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
            });
            $ccRaw = isset($data['cc']) ? rtrim(trim($data['cc']), ',') : '';
            $cc = $ccRaw != ''
                ? array_filter(array_map('trim', explode(',', $ccRaw)), function ($email) {
                    return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
                })
                : [];
            $bccRaw = isset($data['bcc']) ? rtrim(trim($data['bcc']), ',') : '';
            $bcc = $bccRaw != ''
                ? array_filter(array_map('trim', explode(',', $bccRaw)), function ($email) {
                    return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
                })
                : [];

            if (empty($to)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid recipient email addresses provided after filtering.',
                ], 422);
            }

            // Prepare recipients array for the notification service
            $recipients = [
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc
            ];

            // Generate variable replacements
            $replacements = $this->variableReplacer->getVariableReplacements($notification, $data);

            // Replace variables in subject and body
            $subject = $this->variableReplacer->replaceVariables($notification->subject, $replacements);
            $body = $this->variableReplacer->replaceVariables($notification->message, $replacements);

            // Handle CSV attachment
            $csvAttachment = $data['csv_attachment'] ?? null;

            // Send email using the notification service
            $result = $this->notificationService->sendEmail(
                $recipients,
                $subject,
                $body,
                $notification->is_html,
                [], // regular attachments
                $csvAttachment,
                $notification->id
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification sent successfully',
            ]);
        } catch (\Exception $e) {
            Log::error("Error in sendSingleEmail", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle sending scheduled notifications (for cronjob)
     */
    public function sendScheduled()
    {
        $now = now();
        $dueNotifications = $this->scheduler->getDueNotifications($now);

        foreach ($dueNotifications as $notification) {
            Log::info("Processing scheduled notification", [
                'notification_id' => $notification->id,
                'notification_type' => $notification->notification_type,
                'to' => $notification->to
            ]);

            try {
                // Check status and get enrollee IDs or CSV generation data if applicable
                // Pass the notification object for schedule-based date calculation
                $statusResult = $this->checkNotificationStatus($notification->notification_type, $notification->enrollment_id ?? null, $notification);

                Log::info("Status result for notification", [
                    'notification_id' => $notification->id,
                    'notification_type' => $notification->notification_type,
                    'has_status_result' => !empty($statusResult)
                ]);

                $request = new Request([
                    'notification_id' => $notification->id,
                    'to' => $notification->to,
                    'cc' => $notification->cc,
                    'bcc' => $notification->bcc,
                    'use_saved' => true,
                ]);

                $forCount = 0; // Initialize count variable

                // Handle CSV generation for report notifications
                if (is_array($statusResult) && isset($statusResult['type']) && $statusResult['type'] === 'csv_generation') {
                    // Generate CSV attachment
                    $csvAttachment = $this->attachmentHandler->processCsvForScheduledNotification($statusResult);

                    if ($csvAttachment) {
                        $request->merge(['csv_attachment' => $csvAttachment]);
                        $forCount = 1; // CSV reports count as 1 recipient
                    } else {
                        Log::info("No CSV data available for notification", [
                            'notification_id' => $notification->id
                        ]);
                        continue; // Skip this notification
                    }
                }

                // Handle specific enrollee IDs (e.g., for APPROVED BY HMO)
                elseif (!empty($statusResult) && is_array($statusResult) && isset($statusResult[0]) && is_numeric($statusResult[0])) {
                    // Override the 'to' field with enrollee IDs
                    $request->merge(['to' => implode(',', $statusResult)]);
                    $forCount = count($statusResult);
                }

                if ($forCount === 0) {
                    Log::info("No recipients found for notification", [
                        'notification_id' => $notification->id
                    ]);
                    continue;
                }

                $this->send($request);

                Log::info("Notification sent, updating last_sent_at", [
                    'notification_id' => $notification->id
                ]);

                $this->scheduler->updateLastSentTime($notification, $now);
            } catch (\Exception $e) {
                Log::error("Failed to process scheduled notification", [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Scheduled notifications processed.'
        ]);
    }

    private function checkNotificationStatus($notificationType, $enrollmentId = null, $notification = null)
    {
        switch ($notificationType) {
            case 'APPROVED BY HMO (WELCOME EMAIL)':
                return $this->getEnrolleesByStatus($enrollmentId, 'APPROVED', $notification);
            case 'ENROLLMENT START (PENDING)':
                return $this->getEnrolleesByStatus($enrollmentId, 'PENDING', $notification);
            case 'REPORT: ATTACHMENT (SUBMITTED)':
                $getCsvConfigForNotificationType = $this->getCsvConfigForNotificationType('REPORT: ATTACHMENT (SUBMITTED)', $enrollmentId, $notification);

                return $getCsvConfigForNotificationType;
            case 'REPORT: ATTACHMENT (APPROVED)':
                $getCsvConfigForNotificationType = $this->getCsvConfigForNotificationType('REPORT: ATTACHMENT (APPROVED)', $enrollmentId, $notification);

                return $getCsvConfigForNotificationType;
            default:
                return null;
        }
    }

    /**
     * Get all enrollees with specific status updated within date range for the given enrollment
     */
    private function getEnrolleesByStatus($enrollmentId = null, $status = null, $notification = null)
    {
        if (!$enrollmentId) {
            return [];
        }

        $dateRange = $this->scheduler->calculateDateRangeFromSchedule($notification);

        $enrollees = Enrollee::where('enrollment_id', $enrollmentId)
            ->where('enrollment_status', $status)
            ->where('updated_at', '>=', $dateRange['from']) // Use calculated date range
            ->where('updated_at', '<=', $dateRange['to'])   // Use calculated date range
            ->whereNull('deleted_at')
            ->get();

        if ($enrollees->count() > 0) {
            $enrolleeIds = $enrollees->pluck('id')->toArray();
            return $enrolleeIds;
        }

        Log::info("STATUS BY HMO notification: No {$status} enrollees found for enrollment {$enrollmentId} on {$dateRange['from']} to {$dateRange['to']}");

        return [];
    }
}
