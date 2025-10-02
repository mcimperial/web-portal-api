<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\ClientMasterlist\App\Services\EmailSender;
use Modules\ClientMasterlist\App\Models\Attachment;

class SendNotificationController extends Controller
{
    /**
     * Handle sending a notification email.
     */
    public function send(Request $request)
    {
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
        ]);

        $notification = Notification::find($data['notification_id']);
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        try {
            // If 'to' is a list of enrollee IDs, handle in a separate function
            if ($this->isIdList($data['to'])) {
                return $this->sendToEnrolleeIds($data, $notification);
            }

            // If use_saved is true, ignore 'to' and 'cc' from request and use saved values
            $data['enrollee_id'] = $data['attach_enrollee_id'] ?? $data['enrollee_id'];

            // If send_as_multiple is set and there are multiple emails, handle in a separate function
            if (!empty($data['send_as_multiple'])) {
                return $this->sendToMultipleEmails($data, $notification);
            }

            // Otherwise, send as a single email (default)
            return $this->sendSingleEmail($data, $notification);
        } catch (\Exception $e) {
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

        $replacements = $this->getVariableReplacements($notification, $data);
        $messageBody  = $this->replaceVariables($notification->message, $replacements);
        $subjectBody  = $this->replaceVariables($notification->subject, $replacements);

        if (env('EMAIL_PROVIDER_SETTING') === 'infobip') {
            $blockedExtensions = ['ade', 'adp', 'app', 'asp', 'aspx', 'bas', 'bat', 'chm', 'cmd', 'com', 'cpl', 'crt', 'csh', 'exe', 'fxp', 'hlp', 'hta', 'inf', 'ins', 'isp', 'js', 'jse', 'ksh', 'lnk', 'mad', 'maf', 'mag', 'mam', 'maq', 'mar', 'mas', 'mat', 'mau', 'mav', 'maw', 'mda', 'mdb', 'mde', 'mdt', 'mdw', 'mdz', 'msc', 'msi', 'msp', 'mst', 'ops', 'pcd', 'pif', 'prf', 'prg', 'ps1', 'ps1xml', 'ps2', 'ps2xml', 'psc1', 'psc2', 'reg', 'scf', 'scr', 'sct', 'shb', 'shs', 'tmp', 'url', 'vb', 'vbe', 'vbs', 'vsmacros', 'vsw', 'ws', 'wsc', 'wsf', 'wsh', 'xnk'];

            $tempFiles = [];
            $infobipAttachments = [];

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

            $emailService = new EmailSender(
                $to, // pass as array
                $notification->is_html ? $messageBody : nl2br($messageBody),
                $subjectBody ?? 'Notification',
                'default',
                $infobipAttachments, // pass array of ['path','name']
                $cc, // pass as array
                $bcc, // pass as array
                []
            );

            $result = $emailService->send();
            foreach ($tempFiles as $tmp) {
                @unlink($tmp);
            }

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Infobip error: Failed to send email via service',
                ], 500);
            }
        } else {
            Mail::send([], [], function ($message) use ($notification, $to, $cc, $attachments, $messageBody, $subjectBody) {
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
            });
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
            ->where(function ($q) use ($now) {
                $q->whereNull('last_sent_at')
                    ->orWhere('last_sent_at', '<', $now->subMinute());
            })
            ->get();

        foreach ($dueNotifications as $notification) {
            if (!$this->isCronDue($notification->schedule, $now)) continue;

            $request = new Request([
                'notification_id' => $notification->id,
                'to' => $notification->to,
                'cc' => $notification->cc,
                'bcc' => $notification->bcc,
                'use_saved' => true,
            ]);

            $this->send($request);

            $notification->last_sent_at = $now;
            $notification->save();
        }

        return response()->json(['success' => true, 'message' => 'Scheduled notifications processed.']);
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
     * Example: {{employee_name}}, {{enrollment_date}}, etc.
     * Extend this as needed to support more variables.
     */
    private function replaceVariables($text, $replacements = [])
    {
        if (!$text) return $text;
        foreach ($replacements as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
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
        $link = $baseUrl . '/self-enrollment?id=' . $enrollee->uuid;
        $link = '<a href="' . $link . '">Self-Enrollment Portal</a>';

        $replacements = [
            'enrollment_link' => $data['enrollment_link'] ?? $link ?? '',
            'coverage_start_date' => $data['coverage_start_date'] ?? date('F j, Y', strtotime($enrollee->healthInsurance->coverage_start_date)),
            'first_day_of_next_month' => $data['first_day_of_next_month'] ?? date('F j, Y', strtotime('+1 month', strtotime(date('Y-m-01')))),
            'certification_table' => $this->certificationTable($enrollee),
            'submission_table' => $this->submissionTable($enrollee),  // Only include if enrollee has dependents
        ];
        return $replacements;
    }

    private function certificationTable($enrollee = null)
    {
        if (!$enrollee) {
            return '';
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
            'certificate_number' => $getCertificateNumber($enrollee),
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
                    'certificate_number' => $getCertificateNumber($dep),
                    'enrollment_status' => $dep->enrollment_status ?? '',
                ];
            }
        }

        // Build HTML table
        $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
        $html .= '<thead><tr style="background:#f3f3f3;"><th>Relation</th><th>Name</th><th>Certificate #</th><th>Status</th></tr></thead><tbody>';
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
                    'enrollment_status' => $dep->enrollment_status ?? 'N/A',
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
        $html .= '<thead><tr style="background:#f3f3f3;"><th>Relation</th><th>Name</th><th>Status</th></tr></thead><tbody>';

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

        if ($premium > 0) {
            $result = self::PremiumComputation($dependentsArr, $premium, $premiumComputation);
            $html .= '<div style="margin-top:18px; margin-bottom:18px; padding:12px; background:#ebf8ff; border-radius:8px;">';
            $html .= '<div style="font-weight:bold; color:#2b6cb0; margin-bottom:8px; font-size:16px;">Premium Computation</div>';
            $html .= '<table style="width:100%; font-size:18px; margin-bottom:8px;"><tbody>';
            $html .= '<tr><td>Monthly:</td><td style="font-weight:bold;">₱ ' . number_format($result['annual'], 2) . '</td></tr>';
            //$html .= '<tr><td>Monthly:</td><td style="font-weight:bold;font-size:18px;">₱ ' . number_format($result['monthly'], 2) . '</td></tr>';
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

    /**
     * Compute premium breakdown for dependents, similar to the React hook usePremiumComputation.
     *
     * @param array $dependents Array of dependents (each should have 'is_skipping' property if skipping)
     * @param float|int $bill The base premium amount
     * @param string|null $premiumComputation The premium computation string (e.g. "1:50,2:30,ALL:10")
     * @return array [ 'breakdown' => [...], 'annual' => float, 'monthly' => float ]
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
