<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Http\Controllers\SendNotificationController;
use Modules\ClientMasterlist\App\Models\Notification;
use Modules\ClientMasterlist\App\Models\Enrollment;

class TestMaxicareEmail extends Command
{
    protected $signature = 'test:maxicare-email {--enrollment-id=1}';
    protected $description = 'Test sending maxicare declaration email to markimperial@llibi.com';

    public function handle()
    {
        $this->info('Testing Maxicare Declaration Email Sending');
        $this->info('==========================================');
        $this->newLine();

        $enrollmentId = $this->option('enrollment-id');
        
        // Find the enrollment
        $enrollment = Enrollment::find($enrollmentId);
        
        if (!$enrollment) {
            $this->error("Enrollment ID {$enrollmentId} not found.");
            return 1;
        }
        
        $this->info("Using enrollment ID: {$enrollment->id}");
        $this->info("Enrollment title: {$enrollment->title}");
        $this->newLine();
        
        // Create a test notification for maxicare declaration
        $notification = Notification::create([
            'enrollment_id' => $enrollment->id,
            'notification_type' => 'REPORT: ATTACHMENT (APPROVED)', // Use existing type that supports CSV
            'to' => 'markimperial@llibi.com',
            'subject' => 'Maxicare Declaration Report - {{enrollment_title}}',
            'title' => 'Maxicare Declaration Report',
            'message' => 'Please find attached the maxicare declaration report for {{enrollment_title}}.',
            'is_html' => false,
            'is_read' => false,
            'schedule' => null // No schedule to avoid date filtering
        ]);
        
        $this->info("Created test notification ID: {$notification->id}");
        $this->newLine();
        
        // Create test request for sending the maxicare declaration
        $requestData = [
            'notification_id' => $notification->id,
            'to' => 'markimperial@llibi.com'
            // Don't pass csv_attachment manually - let the system generate it based on notification type
        ];
        
        $this->info("Preparing to send maxicare declaration email with CSV attachment...");
        $this->info("Recipient: markimperial@llibi.com");
        $this->info("Notification Type: REPORT: ATTACHMENT (APPROVED)");
        $this->info("This will automatically generate CSV with approved enrollees");
        $this->newLine();
        
        try {
            // Create request object
            $request = new Request($requestData);
            
            // Initialize the SendNotificationController
            $controller = new SendNotificationController();
            
            // Send the notification
            $this->info("Sending email...");
            $response = $controller->send($request);
            
            // Get the response content
            $responseData = json_decode($response->getContent(), true);
            
            if ($responseData['success'] ?? false) {
                $this->info("✅ SUCCESS: Maxicare declaration email sent successfully!");
                $this->info("Details: " . ($responseData['message'] ?? 'Email sent'));
                
                if (isset($responseData['csv_info'])) {
                    $this->info("CSV Info:");
                    $this->info("- File: " . ($responseData['csv_info']['filename'] ?? 'N/A'));
                    $this->info("- Rows: " . ($responseData['csv_info']['data_rows'] ?? 'N/A'));
                }
            } else {
                $this->error("❌ FAILED: " . ($responseData['message'] ?? 'Unknown error'));
                $this->line("Response: " . json_encode($responseData, JSON_PRETTY_PRINT));
            }
            
            // Clean up the test notification
            $notification->delete();
            $this->info("\nCleaned up test notification.");
            
        } catch (\Exception $e) {
            $this->error("❌ EXCEPTION: " . $e->getMessage());
            $this->error("File: " . $e->getFile());
            $this->error("Line: " . $e->getLine());
            
            // Clean up the test notification even if there was an error
            if (isset($notification) && $notification->exists) {
                $notification->delete();
                $this->info("Cleaned up test notification.");
            }
            
            return 1;
        }
        
        $this->newLine();
        $this->info("Test completed.");
        return 0;
    }
}