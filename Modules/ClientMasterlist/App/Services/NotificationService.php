<?php

namespace Modules\ClientMasterlist\App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Services\EmailSender;
use Modules\ClientMasterlist\App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * NotificationService
 * 
 * Handles the core email sending functionality for notifications.
 * Supports both Laravel Mail and Infobip email providers.
 */
class NotificationService
{
    /**
     * Send an email notification
     * 
     * @param array $recipients Array with 'to', 'cc', 'bcc' arrays
     * @param string $subject Email subject
     * @param string $body Email body content
     * @param bool $isHtml Whether the content is HTML
     * @param array $attachments Array of attachment file paths
     * @param array $csvAttachment Optional CSV attachment data
     * @param int $notificationId Notification ID for fetching attachments
     * @return array Result with success status and message
     */
    public function sendEmail($recipients, $subject, $body, $isHtml = true, $attachments = [], $csvAttachment = null, $notificationId = null)
    {
        try {
            // Get notification attachments if notification ID provided
            $notificationAttachments = $this->getNotificationAttachments($notificationId);

            // Merge with provided attachments
            $allAttachments = array_merge($attachments, $notificationAttachments);

            if (env('EMAIL_PROVIDER_SETTING') === 'infobip') {
                return $this->sendViaInfobip($recipients, $subject, $body, $isHtml, $allAttachments, $csvAttachment);
            } else {
                return $this->sendViaLaravelMail($recipients, $subject, $body, $isHtml, $allAttachments, $csvAttachment);
            }
        } catch (\Exception $e) {
            Log::error("Email sending failed", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Send email via Infobip provider
     */
    private function sendViaInfobip($recipients, $subject, $body, $isHtml, $attachments, $csvAttachment)
    {
        $blockedExtensions = [
            'ade',
            'adp',
            'app',
            'asp',
            'aspx',
            'bas',
            'bat',
            'chm',
            'cmd',
            'com',
            'cpl',
            'crt',
            'csh',
            'exe',
            'fxp',
            'hlp',
            'hta',
            'inf',
            'ins',
            'isp',
            'js',
            'jse',
            'ksh',
            'lnk',
            'mad',
            'maf',
            'mag',
            'mam',
            'maq',
            'mar',
            'mas',
            'mat',
            'mau',
            'mav',
            'maw',
            'mda',
            'mdb',
            'mde',
            'mdt',
            'mdw',
            'mdz',
            'msc',
            'msi',
            'msp',
            'mst',
            'ops',
            'pcd',
            'pif',
            'prf',
            'prg',
            'ps1',
            'ps1xml',
            'ps2',
            'ps2xml',
            'psc1',
            'psc2',
            'reg',
            'scf',
            'scr',
            'sct',
            'shb',
            'shs',
            'tmp',
            'url',
            'vb',
            'vbe',
            'vbs',
            'vsmacros',
            'vsw',
            'ws',
            'wsc',
            'wsf',
            'wsh',
            'xnk'
        ];

        $tempFiles = [];
        $infobipAttachments = [];

        // Process CSV attachment first
        if ($csvAttachment) {
            $tempFiles[] = $csvAttachment['path'];
            $infobipAttachments[] = [
                'path' => $csvAttachment['path'],
                'name' => $csvAttachment['name']
            ];
        }

        // Process regular attachments
        foreach ($attachments as $attachment) {
            $processedAttachment = $this->processAttachmentForInfobip($attachment, $blockedExtensions);
            if ($processedAttachment) {
                $tempFiles[] = $processedAttachment['path'];
                $infobipAttachments[] = $processedAttachment;
            }
        }

        Log::info("Sending email via Infobip", [
            'to' => $recipients['to'],
            'subject' => $subject,
            'attachments_count' => count($infobipAttachments)
        ]);

        $emailService = new EmailSender(
            $recipients['to'],
            $isHtml ? $body : nl2br($body),
            strtoupper($subject ?? 'Notification'),
            'default',
            $infobipAttachments,
            $recipients['cc'] ?? [],
            $recipients['bcc'] ?? [],
            []
        );

        $result = $emailService->send();

        // Cleanup temp files
        foreach ($tempFiles as $tmpFile) {
            @unlink($tmpFile);
        }

        // Clean up CSV attachment temp files
        if ($csvAttachment && isset($csvAttachment['temp_path'])) {
            @unlink($csvAttachment['temp_path']);
        }

        Log::info("Email sending result", [
            'success' => $result,
            'provider' => 'infobip'
        ]);

        if (!$result) {
            return [
                'success' => false,
                'message' => 'Infobip error: Failed to send email via service'
            ];
        }

        return [
            'success' => true,
            'message' => 'Email sent successfully via Infobip'
        ];
    }

    /**
     * Send email via Laravel Mail
     */
    private function sendViaLaravelMail($recipients, $subject, $body, $isHtml, $attachments, $csvAttachment)
    {
        Mail::send([], [], function ($message) use ($recipients, $subject, $body, $isHtml, $attachments, $csvAttachment) {
            $message->to($recipients['to'])
                ->subject($subject ?? 'Notification');

            if ($isHtml) {
                $message->html($body ?? '');
            } else {
                $message->text($body ?? '');
            }

            if (!empty($recipients['cc'])) {
                $message->cc($recipients['cc']);
            }

            if (!empty($recipients['bcc'])) {
                $message->bcc($recipients['bcc']);
            }

            if (env('MAIL_FROM_ADDRESS')) {
                $message->from(env('MAIL_FROM_ADDRESS'), env('MAIL_FROM_NAME', 'Notification'));
            }

            // Attach regular files
            foreach ($attachments as $attachment) {
                $message->attach($attachment);
            }

            // Add CSV attachment if provided
            if ($csvAttachment) {
                $message->attach($csvAttachment['path'], [
                    'as' => $csvAttachment['name'],
                    'mime' => 'text/csv'
                ]);
            }
        });

        // Clean up CSV attachment temp files
        if ($csvAttachment) {
            @unlink($csvAttachment['path']);
            if (isset($csvAttachment['temp_path'])) {
                @unlink($csvAttachment['temp_path']);
            }
        }

        return [
            'success' => true,
            'message' => 'Email sent successfully via Laravel Mail'
        ];
    }

    /**
     * Get attachments for a notification from the database
     */
    private function getNotificationAttachments($notificationId)
    {
        if (!$notificationId) {
            return [];
        }

        $attachments = [];
        $attachmentModels = Attachment::where('notification_id', $notificationId)->get();

        foreach ($attachmentModels as $att) {
            if (!empty($att->file_path)) {
                $attachments[] = $att->file_path;
            }
        }

        return $attachments;
    }

    /**
     * Process attachment for Infobip, handling blocked extensions
     */
    private function processAttachmentForInfobip($attachment, $blockedExtensions)
    {
        if (empty($attachment)) {
            return null;
        }

        try {
            // Extract file content from DO Spaces
            $endpoint = rtrim(config('filesystems.disks.spaces.endpoint'), '/');
            $publicUrlPrefix = 'https://llibi-self-enrollment.' . substr($endpoint, 8) . '/';
            $objectKey = ltrim(str_replace($publicUrlPrefix, '', $attachment), '/');
            $fileContents = Storage::disk('spaces')->get($objectKey);
            $originalName = basename($objectKey);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (in_array($ext, $blockedExtensions)) {
                $safeName = $originalName . '.txt';
                $tmp = tempnam(sys_get_temp_dir(), 'attach_');
                $tmpTxt = $tmp . '.txt';
                file_put_contents($tmpTxt, $fileContents);

                return [
                    'path' => $tmpTxt,
                    'name' => $safeName
                ];
            } else {
                $tmp = tempnam(sys_get_temp_dir(), 'attach_');
                file_put_contents($tmp, $fileContents);

                return [
                    'path' => $tmp,
                    'name' => $originalName
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Failed to process attachment", [
                'attachment' => $attachment,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validate and clean email addresses
     */
    public function validateAndCleanEmails($emailString)
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
}
