<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Services\EmailSender;
use Modules\ClientMasterlist\App\Models\Attachment;
use Modules\ClientMasterlist\App\Http\Controllers\ExportEnrolleesController;
use Illuminate\Support\Facades\DB;

use Modules\ClientMasterlist\App\Models\Enrollee;

/**
 * SendNotificationController
 * 
 * Handles sending notification emails to enrollees, including:
 * - Manual notification sending
 * - Scheduled notification processing (for cronjobs)
 * - Automatic targeting of specific enrollee groups based on notification type
 * - CSV report generation and attachment for specific notification types
 * - Schedule-based date range filtering for reports
 * 
 * Special Features:
 * - APPROVED BY HMO (WELCOME EMAIL): Automatically sends to enrollees with 'APPROVED' 
 *   status that were updated on the current day
 * - REPORT: ATTACHMENT (SUBMITTED/APPROVED): Generates CSV reports using ExportEnrolleesController
 *   and attaches them to notification emails with date filtering based on cron schedule
 * - Schedule-based date ranges: Uses notification cron schedule to calculate date ranges
 *   from previous scheduled run to current run for report filtering
 * - Support for multiple email providers (Laravel Mail, Infobip)
 * - Variable replacement in email content (enrollment links, tables, etc.)
 * - Attachment handling with blocked extension protection for Infobip
 * - Automatic CSV file cleanup after email sending
 */
class SendNotificationController extends Controller
{
    /**
     * Handle sending a notification email.
     */
    public function send(Request $request)
    {
        Log::info("Send method called", [
            'request_data' => $request->all(),
            'has_csv_attachment' => $request->has('csv_attachment')
        ]);

        $enrollmentType = $request->input('enrollment_type');
        $enrolleeStatus = $request->input('enrollee_status');

        // If enrollee_status is provided, find all enrollees with that status for this enrollment
        if ($enrolleeStatus && $request->has('notification_id')) {
            $notificationId = $request->input('notification_id');
            $notification = Notification::find($notificationId);

            if ($notification && isset($notification->enrollment_id)) {
                $enrollmentId = $notification->enrollment_id;

                // Find enrollees with the given status and enrollment_id
                $enrolleeQuery = Enrollee::with(['healthInsurance'])
                    ->where('enrollment_id', $enrollmentId)
                    ->where('enrollment_status', $enrolleeStatus)
                    ->whereNull('deleted_at');

                if ($enrollmentType === 'RENEWAL') {
                    $enrolleeQuery->whereHas('healthInsurance', function ($subQ) {
                        $subQ->where('is_renewal', true);
                    });
                } elseif ($enrollmentType === 'REGULAR') {
                    $enrolleeQuery->whereHas('healthInsurance', function ($subQ) {
                        $subQ->where('is_renewal', false);
                    });
                }

                // Apply date filtering if provided
                if ($request->has('dateFrom') && $request->filled('dateFrom')) {
                    $enrolleeQuery->whereDate('updated_at', '>=', $request->input('dateFrom') . ' 00:00:00');
                }

                if ($request->has('dateTo') && $request->filled('dateTo')) {
                    $enrolleeQuery->whereDate('updated_at', '<=', $request->input('dateTo') . ' 23:59:59');
                }

                $enrollees = $enrolleeQuery->get();

                if ($enrollees->count() > 0) {
                    // Collect their IDs
                    $enrolleeIds = $enrollees->pluck('id')->toArray();
                    // Set 'to' as a comma-separated list of IDs
                    $request->merge([
                        'to' => implode(',', $enrolleeIds)
                    ]);
                } else {
                    // No enrollees found with that status
                    return response()->json([
                        'success' => false,
                        'message' => 'No enrollees found with status: ' . $enrolleeStatus,
                    ], 422);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification or enrollment not found for status filter.',
                ], 422);
            }
        }

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
                'message' => $responseData['message'] ?? ''
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

        // Get attachments for this notification (use file_path directly from DO Spaces)
        $attachments = [];
        $attachmentModels = Attachment::where('notification_id', $notification->id)->get();

        foreach ($attachmentModels as $att) {
            if (!empty($att->file_path)) {
                $attachments[] = $att->file_path;
            }
        }

        // Add CSV attachment if provided
        $csvAttachment = $data['csv_attachment'] ?? null;

        $replacements = $this->getVariableReplacements($notification, $data);
        $messageBody  = $this->replaceVariables($notification->message, $replacements);
        $subjectBody  = $this->replaceVariables($notification->subject, $replacements);

        if (env('EMAIL_PROVIDER_SETTING') === 'infobip') {
            $blockedExtensions = ['ade', 'adp', 'app', 'asp', 'aspx', 'bas', 'bat', 'chm', 'cmd', 'com', 'cpl', 'crt', 'csh', 'exe', 'fxp', 'hlp', 'hta', 'inf', 'ins', 'isp', 'js', 'jse', 'ksh', 'lnk', 'mad', 'maf', 'mag', 'mam', 'maq', 'mar', 'mas', 'mat', 'mau', 'mav', 'maw', 'mda', 'mdb', 'mde', 'mdt', 'mdw', 'mdz', 'msc', 'msi', 'msp', 'mst', 'ops', 'pcd', 'pif', 'prf', 'prg', 'ps1', 'ps1xml', 'ps2', 'ps2xml', 'psc1', 'psc2', 'reg', 'scf', 'scr', 'sct', 'shb', 'shs', 'tmp', 'url', 'vb', 'vbe', 'vbs', 'vsmacros', 'vsw', 'ws', 'wsc', 'wsf', 'wsh', 'xnk'];

            $tempFiles = [];
            $infobipAttachments = [];

            // Log CSV attachment status
            Log::info("Email sending - CSV attachment status", [
                'has_csv_attachment' => !empty($csvAttachment),
                'csv_path' => $csvAttachment['path'] ?? null,
                'csv_name' => $csvAttachment['name'] ?? null,
                'csv_file_exists' => $csvAttachment ? file_exists($csvAttachment['path']) : false
            ]);

            // Handle CSV attachment first
            if ($csvAttachment) {
                $tempFiles[] = $csvAttachment['path'];
                $infobipAttachments[] = [
                    'path' => $csvAttachment['path'],
                    'name' => $csvAttachment['name']
                ];
            }

            foreach ($attachmentModels as $att) {
                if (!empty($att->file_path)) {
                    $endpoint = rtrim(config('filesystems.disks.spaces.endpoint'), '/');
                    $publicUrlPrefix = 'https://llibi-self-enrollment.' . substr($endpoint, 8) . '/';
                    $objectKey = ltrim(str_replace($publicUrlPrefix, '', $att->file_path), '/');
                    $fileContents = Storage::disk('spaces')->get($objectKey);
                    $originalName = $att->file_name ?? basename($objectKey);
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    if (in_array($ext, $blockedExtensions)) {
                        $safeName = $originalName . '.txt';
                        $tmp = tempnam(sys_get_temp_dir(), 'attach_');
                        $tmpTxt = $tmp . '.txt';
                        file_put_contents($tmpTxt, $fileContents);
                        $tempFiles[] = $tmpTxt;
                        $infobipAttachments[] = [
                            'path' => $tmpTxt,
                            'name' => $safeName
                        ];
                    } else {
                        $tmp = tempnam(sys_get_temp_dir(), 'attach_');
                        file_put_contents($tmp, $fileContents);
                        $tempFiles[] = $tmp;
                        $infobipAttachments[] = [
                            'path' => $tmp,
                            'name' => $originalName
                        ];
                    }
                }
            }

            // Log email sending attempt
            Log::info("Sending email via Infobip", [
                'to' => $to,
                'subject' => $subjectBody,
                'attachments_count' => count($infobipAttachments),
                'attachments' => array_map(function ($att) {
                    return [
                        'name' => $att['name'],
                        'file_exists' => file_exists($att['path'])
                    ];
                }, $infobipAttachments)
            ]);

            $emailService = new EmailSender(
                $to, // pass as array
                $notification->is_html ? $messageBody : nl2br($messageBody),
                strtoupper($subjectBody ?? 'Notification'),
                'default',
                $infobipAttachments, // pass array of ['path','name']
                $cc, // pass as array
                $bcc, // pass as array
                []
            );

            $result = $emailService->send();

            // Log email sending result
            Log::info("Email sending result", [
                'success' => $result,
                'provider' => 'infobip'
            ]);

            foreach ($tempFiles as $tmp) {
                @unlink($tmp);
            }

            // Clean up CSV attachment temp files
            if ($csvAttachment && isset($csvAttachment['temp_path'])) {
                @unlink($csvAttachment['temp_path']);
            }

            if (!$result) {
                // Log failed notification
                $this->logNotificationSending($notification, $data, 'FAILED', 'Manual');

                return response()->json([
                    'success' => false,
                    'message' => 'Infobip error: Failed to send email via service',
                ], 500);
            }

            // Log successful notification
            $this->logNotificationSending($notification, $data, 'SUCCESS', 'Manual');
        } else {
            Mail::send([], [], function ($message) use ($notification, $to, $cc, $bcc, $attachments, $messageBody, $subjectBody, $csvAttachment) {
                $message->to($to)
                    ->subject($subjectBody ?? 'Notification');
                if ($notification->is_html) {
                    $message->html($messageBody ?? '');
                } else {
                    $message->text($messageBody ?? '');
                }
                if (!empty($cc)) {
                    $message->cc($cc);
                }
                if (!empty($bcc)) {
                    $message->bcc($bcc);
                }
                if (env('MAIL_FROM_ADDRESS')) {
                    $message->from(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME', 'Notification'));
                }
                if (!empty($attachments)) {
                    foreach ($attachments as $file) {
                        $message->attach($file);
                    }
                }
                // Add CSV attachment if provided
                if ($csvAttachment) {
                    $message->attach($csvAttachment['path'], [
                        'as' => $csvAttachment['name'],
                        'mime' => 'text/csv'
                    ]);
                }
            });

            // Clean up CSV attachment temp files for Laravel Mail
            if ($csvAttachment) {
                @unlink($csvAttachment['path']);
                if (isset($csvAttachment['temp_path'])) {
                    @unlink($csvAttachment['temp_path']);
                }
            }

            // Log successful notification
            $this->logNotificationSending($notification, $data, 'SUCCESS', 'Manual');
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully',
        ]);
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
                    'message' => 'Enrollee not found or missing email.'
                ];
                continue;
            }
            $singleData = $data;
            $singleData['to'] = $enrollee->email1;
            $singleData['cc'] = $data['cc'] ?? null;
            $singleData['bcc'] = $data['bcc'] ?? null;
            $singleData['enrollee_id'] = $enrolleeId;

            // For each enrollee, respect send_as_multiple flag
            $result = $this->sendToMultipleEmails($singleData, $notification);

            $responseData = $result->getData(true);

            $results[] = [
                'enrollee_id' => $enrolleeId,
                'success' => $responseData['success'] ?? false,
                'message' => $responseData['message'] ?? ''
            ];
        }
        return response()->json([
            'success' => true,
            'message' => 'Notifications sent to multiple enrollees.',
            'results' => $results
        ]);
    }

    /**
     * Handle sending scheduled notifications (for cronjob)
     */
    public function sendScheduled()
    {
        $now = now();
        $dueNotifications = Notification::whereNotNull('schedule')
            ->whereNull('deleted_at')
            ->get();

        foreach ($dueNotifications as $notification) {
            // Only check if the cron schedule is due, ignore last_sent_at
            if (!$this->isCronDue($notification->schedule, $now)) continue;

            Log::info("Processing scheduled notification", [
                'notification_id' => $notification->id,
                'notification_type' => $notification->notification_type,
                'to' => $notification->to
            ]);

            // Check status and get enrollee IDs or CSV generation data if applicable
            // Pass the notification object for schedule-based date calculation
            $statusResult = $this->checkNotificationStatus($notification->notification_type, $notification->enrollment_id ?? null, $notification);

            Log::info("Status result for notification", [
                'notification_id' => $notification->id,
                'status_result_type' => is_array($statusResult) ? ($statusResult['type'] ?? 'unknown') : gettype($statusResult),
                'has_status_result' => !empty($statusResult)
            ]);

            $request = new Request([
                'notification_id' => $notification->id,
                'to' => $notification->to,
                'cc' => $notification->cc,
                'bcc' => $notification->bcc,
                'use_saved' => true,
                'type' => 'scheduled',
            ]);

            $forCount = 0; // Initialize count variable

            // Handle CSV generation for report notifications
            if (is_array($statusResult) && isset($statusResult['type']) && $statusResult['type'] === 'csv_generation') {
                $csvAttachment = $this->generateCsvAttachment(
                    $statusResult
                );

                // Check if CSV has actual data rows before proceeding
                if ($csvAttachment && isset($csvAttachment['has_data']) && $csvAttachment['has_data']) {
                    // Store the CSV attachment info for use in email sending
                    $request->merge([
                        'csv_attachment' => $csvAttachment
                    ]);

                    Log::info("CSV attachment added to request - has data", [
                        'notification_id' => $notification->id,
                        'csv_filename' => $csvAttachment['name'],
                        'data_rows' => $csvAttachment['data_rows']
                    ]);

                    $forCount = 1; // CSV notification counts as 1 valid recipient
                } else if ($csvAttachment) {

                    // Clean up empty CSV file
                    if (isset($csvAttachment['path']) && file_exists($csvAttachment['path'])) {
                        @unlink($csvAttachment['path']);
                    }

                    if (isset($csvAttachment['temp_path'])) {
                        @unlink($csvAttachment['temp_path']);
                    }

                    Log::info("CSV attachment skipped - no data rows", [
                        'notification_id' => $notification->id,
                        'data_rows' => $csvAttachment['data_rows'] ?? 0,
                        'enrollment_id' => $statusResult['enrollment_id'],
                        //'status' => $statusResult['status']
                    ]);

                    // Skip sending this notification since there's no data
                    continue;
                } else {
                    Log::warning("Failed to generate CSV attachment", [
                        'notification_id' => $notification->id,
                        'enrollment_id' => $statusResult['enrollment_id']
                    ]);

                    // Skip this notification
                    continue;
                }
                // Handle specific enrollee IDs (e.g., for APPROVED BY HMO)
            } else if (!empty($statusResult) && is_array($statusResult) && isset($statusResult[0]) && is_numeric($statusResult[0])) {
                $request->merge([
                    'to' => implode(',', $statusResult)
                ]);

                $forCount = count($statusResult);
            }


            if ($forCount === 0) {
                Log::info("No recipients found for notification, skipping send", [
                    'notification_id' => $notification->id
                ]);
                continue; // Skip sending if no recipients
            }

            $response = $this->send($request);

            // Log scheduled notification result
            $responseData = $response->getData(true);
            $status = $responseData['success'] ? 'SUCCESS' : 'FAILED';
            $this->logNotificationSending($notification, $request->all(), $status, 'Scheduled');

            Log::info("Notification sent, updating last_sent_at", [
                'notification_id' => $notification->id
            ]);

            $notification->last_sent_at = $now;
            $notification->save();
        }

        return response()->json(['success' => true, 'message' => 'Scheduled notifications processed.']);
    }

    private function checkNotificationStatus($notificationType, $enrollmentId = null, $notification = null)
    {
        // Use passed notification or find it if not provided
        if (!$notification) {
            $notification = Notification::with(['enrollment.insuranceProvider'])
                ->where('enrollment_id', $enrollmentId)
                ->where('notification_type', $notificationType)
                ->first();
        }

        $dateRange = $this->calculateDateRangeFromSchedule($notification);

        // Get insurance provider name from enrollment
        $insuranceProvider = '';
        if ($notification && $notification->enrollment && $notification->enrollment->insuranceProvider) {
            $insuranceProvider = $notification->enrollment->insuranceProvider->title;
        }

        $columns = [];

        switch ($notificationType) {
            case 'APPROVED BY HMO (WELCOME EMAIL)':
                return $this->getEnrolleesByStatus($enrollmentId, 'NC', 'APPROVED', $notification);
            case 'ENROLLMENT START (PENDING)':
                return $this->getEnrolleesByStatus($enrollmentId, true, 'PENDING', $notification);
            case 'ENROLLMENT START W/OUT DEP (PENDING)':
                return $this->getEnrolleesByStatus($enrollmentId, false, 'PENDING', $notification);
            case 'REPORT: ATTACHMENT (SUBMITTED)':
                // Return data for CSV generation instead of enrollee IDs
                return [
                    'type' => 'csv_generation',
                    'maxicare_customized_column' => $insuranceProvider === 'MAXICARE' ? true : false,
                    'enrollment_id' => $enrollmentId,
                    'enrollment_status' => 'SUBMITTED',
                    'insurance_provider' => $insuranceProvider,
                    'export_enrollment_type' => 'REGULAR',
                    'is_renewal' => false,
                    'with_dependents' => true,
                    'date_from' => $dateRange['from'],
                    'date_to' => $dateRange['to'],
                    'columns' => $columns
                ];
            case 'REPORT: ATTACHMENT (APPROVED)':
                // Return data for CSV generation for approved enrollees
                return [
                    'type' => 'csv_generation',
                    'enrollment_id' => $enrollmentId,
                    'enrollment_status' => 'APPROVED',
                    'insurance_provider' => $insuranceProvider,
                    'export_enrollment_type' => 'REGULAR',
                    'is_renewal' => false,
                    'with_dependents' => true,
                    'date_from' => $dateRange['from'],
                    'date_to' => $dateRange['to'],
                    'columns' => $columns
                ];
            default:
                // No action needed for other types
                return null;
        }
    }

    /**
     * Get all enrollees with APPROVED status updated today for the given enrollment
     */
    private function getEnrolleesByStatus($enrollmentId = null, $withDependents, $status = null, $notification = null)
    {
        if (!$enrollmentId) {
            return [];
        }

        $dateRange = $this->calculateDateRangeFromSchedule($notification);

        $enrollees = Enrollee::with(['healthInsurance'])
            ->where('enrollment_id', $enrollmentId)
            ->where('enrollment_status', $status)
            ->where('updated_at', '>=', $dateRange['from']) // Use calculated date range
            ->where('updated_at', '<=', $dateRange['to'])   // Use calculated date range
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at');

        if ($status === 'APPROVED') {
            $enrollees = $enrollees->whereHas('healthInsurance', function ($subQ) {
                $subQ->where('is_renewal', false);
            });
        }

        if ($withDependents <> 'NC') {
            $enrollees = $enrollees->where('with_dependents', $withDependents);
        }

        $enrollees = $enrollees->get();

        if ($enrollees->count() > 0) {
            $enrolleeIds = $enrollees->pluck('id')->toArray();

            // For scheduled notifications, check for duplicates in notification logs
            // All enrollees in this table are principals (cm_principal table)
            $filteredIds = [];
            foreach ($enrolleeIds as $enrolleeId) {
                $enrollee = $enrollees->firstWhere('id', $enrolleeId);

                // Check if this principal already has a notification log with the same status
                // within the last 24 hours to prevent immediate duplicates
                $existingLog = DB::table('cm_notification_logs')
                    ->where('principal_id', $enrolleeId)
                    ->where('notification_id', $notification->id)
                    ->where('status', 'SUCCESS')
                    //->where('date_sent', '>=', now()->subHours(24)) // Check last 24 hours
                    ->where(function ($query) use ($status) {
                        $query->where('details', 'like', '%"enrollment_status":"' . $status . '"%')
                            ->orWhere('details', 'like', '%"enrollment_status":null%'); // Handle null status cases
                    })
                    ->orderBy('date_sent', 'desc')
                    ->first();

                if ($existingLog) {
                    Log::info("Skipping duplicate scheduled notification for principal", [
                        'principal_id' => $enrolleeId,
                        'notification_id' => $notification->id,
                        'notification_type' => $notification->notification_type,
                        'status' => $status,
                        'existing_log_date' => $existingLog->date_sent ?? 'unknown',
                        'hours_since_last_send' => now()->diffInHours($existingLog->date_sent ?? now())
                    ]);
                    continue; // Skip this principal
                }

                // Add to filtered list (no duplicate found)
                $filteredIds[] = $enrolleeId;
            }

            if (count($filteredIds) !== count($enrolleeIds)) {
                Log::info("Filtered out duplicate notifications", [
                    'notification_id' => $notification->id,
                    'original_count' => count($enrolleeIds),
                    'filtered_count' => count($filteredIds),
                    'status' => $status
                ]);
            }

            return $filteredIds;
        }

        Log::info("STATUS BY HMO notification: No {$status} enrollees found for enrollment {$enrollmentId} on {$dateRange['from']} to {$dateRange['to']}");

        return [];
    }

    /**
     * Log notification sending activity
     */
    private function logNotificationSending($notification, $data, $status, $type = 'Manual')
    {
        try {
            // Extract recipient emails for logging
            $recipients = [];
            if (isset($data['to'])) {
                $toRaw = rtrim(trim($data['to']), ',');
                $recipients = array_filter(array_map('trim', explode(',', $toRaw)), function ($email) {
                    return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
                });
            }

            // Determine if this notification was sent to a principal
            $principalId = null;
            $sentToPrincipal = false;

            // Check if enrollee_id is provided and it's a valid principal
            if (isset($data['enrollee_id']) && $data['enrollee_id']) {
                $enrollee = Enrollee::find($data['enrollee_id']);
                if ($enrollee) {
                    // All enrollees in cm_principal table are principals
                    $principalId = $enrollee->id;
                    $sentToPrincipal = true;
                }
            }

            // If no specific enrollee_id, check if notification type typically targets principals
            if (!$sentToPrincipal && $notification && in_array($notification->notification_type, [
                'APPROVED BY HMO (WELCOME EMAIL)',
                'ENROLLMENT START (PENDING)',
                'ENROLLMENT START W/OUT DEP (PENDING)'
            ])) {
                // For these notification types, try to find the principal from enrollment
                if ($notification->enrollment_id) {
                    $principal = Enrollee::where('enrollment_id', $notification->enrollment_id)
                        ->first(); // All Enrollee records are principals, no need for whereNull check
                    if ($principal) {
                        $principalId = $principal->id;
                        $sentToPrincipal = true;
                    }
                }
            }

            // Prepare log details
            $enrollmentStatus = null;
            if ($sentToPrincipal && $principalId) {
                $principal = Enrollee::find($principalId);
                if ($principal) {
                    $enrollmentStatus = $principal->enrollment_status;
                }
            }

            $details = [
                'notification_type' => $notification->notification_type ?? 'Unknown',
                'sending_type' => $notification->type ?? $type, // 'Manual' or 'Scheduled'
                'status' => $status, // 'SUCCESS' or 'FAILED'
                'recipients' => $recipients,
                'recipient_count' => count($recipients),
                'enrollment_id' => $notification->enrollment_id ?? null,
                'enrollment_status' => $enrollmentStatus, // Add enrollment status for duplicate detection
                'sent_to_principal' => $sentToPrincipal
            ];

            // Add additional context for scheduled notifications
            if ($type === 'Scheduled') {
                $details['schedule'] = $notification->schedule ?? null;
                $details['auto_generated'] = true;
            }

            // Create notification log entry
            DB::table('cm_notification_logs')->insert([
                'notification_id' => $notification->id,
                'principal_id' => $sentToPrincipal ? $principalId : null, // Only include if sent to principal
                'date_sent' => now(),
                'status' => $status,
                'details' => json_encode($details),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            Log::info("Notification sending logged", [
                'notification_id' => $notification->id,
                'principal_id' => $sentToPrincipal ? $principalId : null,
                'type' => $type,
                'status' => $status,
                'recipients' => implode(', ', $recipients)
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to log notification sending", [
                'notification_id' => $notification->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Calculate date range from yesterday's schedule time to today's schedule time
     * Based on the notification's cron schedule
     */
    private function calculateDateRangeFromSchedule($notification = null)
    {
        $now = now();

        if (!$notification || !$notification->schedule) {
            // Default to yesterday 00:00:00 onwards if no schedule
            return [
                'from' => $now->copy()->subDay()->startOfDay()->format('Y-m-d H:i:s'),
                'to' => $now->format('Y-m-d H:i:s')
            ];
        }

        try {
            // Parse the cron expression to get the scheduled time
            $cronExp = \Cron\CronExpression::factory($notification->schedule);
            // Get the current/previous scheduled time
            $currentScheduledTime = $cronExp->getPreviousRunDate($now);
            // Convert to Carbon for easier manipulation
            $currentScheduledTime = \Carbon\Carbon::instance($currentScheduledTime);

            // Determine interval based on cron schedule pattern
            $scheduleArray = explode(' ', $notification->schedule);
            $minutes = $scheduleArray[0] ?? '*';
            $hours = $scheduleArray[1] ?? '*';
            $days = $scheduleArray[2] ?? '*';
            $months = $scheduleArray[3] ?? '*';
            $weekdays = $scheduleArray[4] ?? '*';

            // Check if schedule is * * * * * (every minute)
            if ($notification->schedule === '* * * * *') {
                // Every minute - use yesterday datetime to today datetime
                $dateFrom = $now->copy()->subDay();
                $dateTo = $now;
            } else {
                $dateFrom = $currentScheduledTime->copy();
                $dateTo = $now;

                // Determine the interval and subtract accordingly to get the "from" date
                if ($minutes !== '*' && $hours === '*') {
                    // Every minute (but not * * * * *) - subtract 1 minute
                    $dateFrom->subMinutes(1);
                } elseif ($hours === '*') {
                    // Hourly (every hour) - subtract 1 hour
                    $dateFrom->subHours(1);
                } elseif ($days === '*') {
                    // Daily (every day) - subtract 1 day
                    $dateFrom->subDays(1);
                } elseif ($months === '*') {
                    // Monthly (every month) - subtract 1 month
                    $dateFrom->subMonths(1);
                } else {
                    // Default to 1 day for other patterns
                    $dateFrom->subDays(1);
                }
            }

            Log::info("Date range calculated based on schedule interval", [
                'schedule' => $notification->schedule,
                'current_scheduled' => $currentScheduledTime->format('Y-m-d H:i:s'),
                'from' => $dateFrom->format('Y-m-d H:i:s'),
                'to' => $dateTo->format('Y-m-d H:i:s'),
                'notification_type' => $notification->notification_type ?? 'unknown'
            ]);

            return [
                'from' => $dateFrom->format('Y-m-d H:i:s'),
                'to' => $dateTo->format('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            Log::warning("Failed to parse cron schedule, using default date range", [
                'schedule' => $notification->schedule,
                'error' => $e->getMessage()
            ]);

            // Fallback to default range
            return [
                'from' => $now->copy()->subDay()->startOfDay()->format('Y-m-d H:i:s'),
                'to' => $now->format('Y-m-d H:i:s')
            ];
        }
    }

    /**
     * Generate CSV attachment using ExportEnrolleesController
     */
    private function generateCsvAttachment($statusResult)
    {
        try {

            // Create a request object with the parameters
            $request = new Request([
                'maxicare_customized_column' => $statusResult['maxicare_customized_column'],
                'enrollment_id' => $statusResult['enrollment_id'],
                'enrollment_status' => $statusResult['enrollment_status'] ?? null,
                'export_enrollment_type' => $statusResult['export_enrollment_type'] ?? 'REGULAR',
                'is_renewal' => $statusResult['is_renewal'] ?? false,
                'with_dependents' => $statusResult['with_dependents'] ?? true,
                'date_from' => $statusResult['date_from'] ?? null,
                'date_to' => $statusResult['date_to'] ?? null,
                'columns' => $statusResult['columns'] ?? []
            ]);

            // Create ExportEnrolleesController instance and call the export method
            $exportController = new ExportEnrolleesController();

            $response = $exportController->exportEnrolleesForAttachment($request);

            // Get the CSV content from the response
            $csvContent = $response->getContent();

            // Count the number of rows in the CSV (excluding header)
            $csvLines = explode("\n", trim($csvContent));
            $totalRows = count($csvLines);
            $dataRows = $totalRows - 1; // Subtract 1 for header row

            // Remove empty lines from count
            $dataRows = count(array_filter($csvLines, function ($line) {
                return trim($line) !== '';
            })) - 1; // Subtract 1 for header

            // Generate a temporary file
            $filename = 'ENROLLEES_' . ($statusResult['enrollment_status'] ?? 'ALL') . '_' . date('Ymd_His') . '.csv';
            $tempPath = tempnam(sys_get_temp_dir(), 'csv_attachment_');
            $tempCsvPath = $tempPath . '.csv';

            // Write CSV content to temporary file
            file_put_contents($tempCsvPath, $csvContent);

            return [
                'path' => $tempCsvPath,
                'name' => $filename,
                'temp_path' => $tempPath, // Store original temp path for cleanup
                'has_data' => $dataRows > 0,
                'data_rows' => $dataRows
            ];
        } catch (\Exception $e) {
            Log::error("Failed to generate CSV attachment: " . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Check if a cron expression is due now
     */
    private function isCronDue($cron, $now)
    {
        try {
            $cronExp = \Cron\CronExpression::factory($cron);
            return $cronExp->isDue($now);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Replace variable placeholders in the message/subject with actual data.
     * Supports case-insensitive variable replacement.
     */
    private function replaceVariables($text, $replacements = [])
    {
        if (!$text) return $text;

        foreach ($replacements as $key => $value) {
            // Case-sensitive replacement (original behavior)
            $text = str_replace('{{' . $key . '}}', $value, $text);

            // Case-insensitive replacement for uppercase variables
            $text = str_ireplace('{{' . $key . '}}', $value, $text);
        }
        return $text;
    }

    /**
     * Get variable replacements for a notification.
     * You can expand this to fetch from DB or other sources as needed.
     */
    private function getVariableReplacements($notification, $data = [])
    {
        // Determine which enrollee to use: prefer enrollee_id from $data, else from notification->enrollment_id
        $enrollee = null;
        if (!empty($data['enrollee_id'])) {
            $enrollee = \Modules\ClientMasterlist\App\Models\Enrollee::where('id', $data['enrollee_id'])
                ->whereNull('deleted_at')
                ->first();
        }

        if (!$enrollee && $notification && isset($notification->enrollment_id)) {
            $enrollee = \Modules\ClientMasterlist\App\Models\Enrollee::where('enrollment_id', $notification->enrollment_id)
                ->whereNull('deleted_at')
                ->first();
        }

        $baseUrl = env('FRONTEND_URL');
        // Ensure the URL is properly formatted with protocol and no double slashes
        $baseUrl = rtrim($baseUrl, '/');

        // Fix any malformed URLs that might have incorrect protocol format
        $baseUrl = preg_replace('/^https?:\/+/', 'https://', $baseUrl);

        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }

        $link = $baseUrl . '/self-enrollment?id=' . $enrollee->uuid;
        $link = '<a href="' . $link . '">Self-Enrollment Portal</a>';

        $coverage_start_date = $enrollee->healthInsurance->coverage_start_date;

        $coverageStartDate = date('F j, Y');
        if (!empty($coverage_start_date)) {
            $coverageStartDate = date('F j, Y', strtotime($coverage_start_date));
        }

        $firstDayOfNextMonth = date('F j, Y', strtotime('+1 month', strtotime(date('Y-m-01'))));

        $replacements = [
            'enrollment_link' => $data['enrollment_link'] ?? $link,
            'coverage_start_date' => $data['coverage_start_date'] ?? $coverageStartDate,
            'first_day_of_next_month' => $data['first_day_of_next_month'] ?? $firstDayOfNextMonth,
            'date_today' => date('F j, Y'),
            'certification_table' => $this->certificationTable($enrollee),
            'submission_table' => $this->submissionTable($enrollee),  // Only include if enrollee has dependents

            // Add uppercase versions for backward compatibility
            'ENROLLMENT_LINK' => $data['enrollment_link'] ?? $link,
            'COVERAGE_START_DATE' => $data['coverage_start_date'] ?? $coverageStartDate,
            'FIRST_DAY_OF_NEXT_MONTH' => $data['first_day_of_next_month'] ?? $firstDayOfNextMonth,
            'DATE_TODAY' => date('F j, Y'),
            'CERTIFICATION_TABLE' => $this->certificationTable($enrollee),
            'SUBMISSION_TABLE' => $this->submissionTable($enrollee),
        ];
        return $replacements;
    }

    private function certificationTable($enrollee = null)
    {
        if (!$enrollee) {
            return '';
        }

        // Get insurance provider to determine column header
        $insuranceProvider = '';
        if ($enrollee && $enrollee->enrollment && $enrollee->enrollment->insuranceProvider) {
            $insuranceProvider = strtoupper($enrollee->enrollment->insuranceProvider->title ?? '');
        }

        // Determine column header based on provider
        $certificateColumnHeader = 'Certificate No.'; // Default
        if ($insuranceProvider === 'MAXICARE') {
            $certificateColumnHeader = 'Card Number';
        } elseif ($insuranceProvider === 'PHILCARE') {
            $certificateColumnHeader = 'Certificate No.';
        }

        // Helper to get certificate_number from joined health_insurance if available
        $getCertificateNumber = function ($person) {
            // If relation loaded, use it; otherwise fallback
            if (isset($person->healthInsurance) && !empty($person->healthInsurance->certificate_number)) {
                return $person->healthInsurance->certificate_number;
            }
            return $person->certificate_number ?? '';
        };

        // Prepare rows: principal first, then dependents
        $rows = [];
        // Principal
        $rows[] = [
            'relation' => 'PRINCIPAL',
            'name' => trim(($enrollee->first_name ?? '') . ' ' . ($enrollee->last_name ?? '')),
            'certificate_number' => $getCertificateNumber($enrollee) ?? 'N/A',
            'enrollment_status' => $enrollee->enrollment_status ?? '',
        ];

        // Dependents
        if (method_exists($enrollee, 'dependents')) {
            foreach ($enrollee->dependents as $dep) {
                $status = strtoupper($dep->status ?? '');
                $enrollmentStatus = strtoupper($dep->enrollment_status ?? '');
                if ($enrollmentStatus === 'SKIPPED' || $status === 'INACTIVE') {
                    continue;
                }
                $rows[] = [
                    'relation' => 'DEPENDENT',
                    'name' => trim(($dep->first_name ?? '') . ' ' . ($dep->last_name ?? '')),
                    'certificate_number' => $getCertificateNumber($dep) ?? 'N/A',
                    'enrollment_status' => $dep->enrollment_status ?? 'N/A',
                ];
            }
        }

        // Build HTML table
        $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
        $html .= '<thead><tr style="background:#f3f3f3;"><th>Relation</th><th>Name</th><th>' . htmlspecialchars($certificateColumnHeader) . '</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['relation']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['certificate_number']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['enrollment_status']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function submissionTable($enrollee = null)
    {
        if (!$enrollee) {
            return '';
        }

        // Prepare rows: principal first, then dependents
        $rows = [];
        // Principal
        $rows[] = [
            'relation' => 'PRINCIPAL',
            'name' => trim(($enrollee->first_name ?? '') . ' ' . ($enrollee->last_name ?? '')),
            'certificate_number' => $enrollee->healthInsurance->certificate_number ?? '',
            'enrollment_status' => $enrollee->enrollment_status ?? '',
        ];

        // Dependents
        $dependentsArr = [];
        if (method_exists($enrollee, 'dependents')) {
            foreach ($enrollee->dependents as $dep) {
                $rows[] = [
                    'relation' => 'DEPENDENT',
                    'name' => trim(($dep->first_name ?? '') . ' ' . ($dep->last_name ?? '')),
                    'certificate_number' => $dep->certificate_number ?? 'N/A',
                    'enrollment_status' => $dep->enrollment_status == 'OVERAGE' || $dep->enrollment_status == 'SKIPPED' ? $dep->enrollment_status : '--',
                    //'skipping' => $dep->enrollment_status === 'SKIPPED' ? ' (skipped)' : '',
                ];
                // For premium computation, use array form and add healthInsurance->is_skipping if present
                $depArr = is_array($dep) ? $dep : (array)$dep;
                $depArr['is_skipping'] = $dep->enrollment_status === 'SKIPPED' ? 1 : 0;
                $dependentsArr[] = $depArr;
            }
        }

        // Build HTML table
        $html = '<b>Below is the summary of your enrollment:</b><br /><table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';

        $html .= '
            <thead>
                <tr style="background:#f3f3f3;">
                    <th>Relation</th>
                    <th>Name</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['relation']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['enrollment_status']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Premium Computation Section
        $premium = 0;
        $premiumComputation = $enrollee->enrollment->premium_computation ?? null;

        // Prefer enrollment premium if present, else healthInsurance
        if (!empty($enrollee->enrollment) && isset($enrollee->enrollment->premium) && $enrollee->enrollment->premium > 0) {
            $premium = $enrollee->enrollment->premium;
        }

        if (!empty($enrollee->healthInsurance) && isset($enrollee->healthInsurance->premium) && $enrollee->healthInsurance->premium > 0) {
            $premium = $enrollee->healthInsurance->premium;
        }

        if (!empty($enrollee->healthInsurance) && $enrollee->healthInsurance->is_company_paid) {
            $premium = 0; // Default if not set
        }

        if ($premium > 0 && count($dependentsArr) > 0) {
            $result = self::PremiumComputation($dependentsArr, $premium, $premiumComputation);
            $html .= '<div style="margin-top:18px; margin-bottom:18px; padding:12px; background:#ebf8ff; border-radius:8px;">';
            $html .= '<div style="font-weight:bold; color:#2b6cb0; margin-bottom:8px; font-size:16px;">Premium Computation</div>';
            $html .= '<table style="width:100%; font-size:18px; margin-bottom:8px;"><tbody>';
            $html .= '<tr><td>' . ($enrollee->enrollment->premium_variable ?? "TOTAL") . ':</td><td style="font-weight:bold;">₱ ' . number_format($result['annual'], 2) . '</td></tr>';
            $html .= '<tr style="' . ($enrollee->enrollment->with_monthly ? "" : "display:none;") . '"><td>MONTHLY:</td><td style="font-weight:bold;font-size:18px;">₱ ' . number_format($result['monthly'], 2) . '</td></tr>';
            $html .= '</tbody></table>';
            $html .= '<div style="font-weight:bold; margin-bottom:4px; font-size:16px;">Breakdown</div>';
            $html .= '<table style="border-collapse:collapse; width:100%; font-size:15px;"><tbody>';
            foreach ($result['breakdown'] as $row) {
                $html .= '<tr style="border-bottom:1px solid #e2e8f0;">';
                $html .= '<td>' . htmlspecialchars($row['dependentCount']) . ' Dependent:<br />' . htmlspecialchars($row['percentage']) . ' of ₱ ' . number_format($premium, 2) . '</td>';
                $html .= '<td style="font-weight:bold;">₱ ' . number_format($row['computed'], 2) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $html .= '</div>';
        }

        return $html;
    }

    /*
     * Compute premium breakdown for dependents, similar to the React hook usePremiumComputation.
     */
    public static function PremiumComputation($dependents = [], $bill = 0, $premiumComputation = null)
    {
        $breakdown = [];
        $percentMap = [];
        if (is_string($premiumComputation) && trim($premiumComputation) !== '') {
            $parts = array_map('trim', explode(',', $premiumComputation));
            foreach ($parts as $part) {
                $split = explode(':', $part);
                if (count($split) === 2 && is_numeric($split[1])) {
                    $label = trim($split[0]);
                    $percentMap[$label] = floatval($split[1]);
                }
            }
        }

        // Only count non-skipped dependents for numbering, as in the React hook
        $depIndex = 0;
        foreach ($dependents as $item) {
            $isSkipping = false;
            if (isset($item['is_skipping'])) {
                $val = $item['is_skipping'];
                $isSkipping = ($val === true || $val === 1 || $val === '1');
            }
            if ($isSkipping) {
                continue;
            }
            $depIndex++;
            $percent = 0;
            if (isset($percentMap[(string)$depIndex])) {
                $percent = $percentMap[(string)$depIndex];
            } else {
                // Case-insensitive ALL key
                foreach ($percentMap as $k => $v) {
                    if (strtoupper($k) === 'REST') {
                        $percent = $v;
                        break;
                    }
                }
            }
            $breakdown[] = [
                'dependentCount' => (string)$depIndex,
                'percentage' => $percent . '%',
                'computed' => $bill * ($percent / 100),
            ];
        }
        $annual = array_reduce($breakdown, function ($sum, $row) {
            return $sum + $row['computed'];
        }, 0);
        $monthly = $annual / 12;
        return [
            'breakdown' => $breakdown,
            'annual' => $annual,
            'monthly' => $monthly,
        ];
    }
}
