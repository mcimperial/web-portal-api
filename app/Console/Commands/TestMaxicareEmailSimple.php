<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Http\Controllers\SendNotificationController;
use Modules\ClientMasterlist\App\Http\Controllers\ExportEnrolleesController;
use Modules\ClientMasterlist\App\Models\Notification;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Illuminate\Support\Facades\Log;

class TestMaxicareEmailSimple extends Command
{
    protected $signature = 'test:maxicare-simple {--enrollment-id=1}';
    protected $description = 'Test maxicare email with direct CSV generation and sending';

    public function handle()
    {
        $this->info('Testing Maxicare Declaration Email - Simple Version');
        $this->info('====================================================');
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
        
        try {
            // Step 1: Generate CSV directly
            $this->info("Step 1: Generating CSV for APPROVED enrollees...");
            
            $exportRequest = new Request([
                'enrollment_id' => $enrollment->id,
                'export_enrollment_type' => 'REGULAR',
                'enrollment_status' => 'APPROVED',
                'maxicare_customized_column' => 'MAXI-SCVP',
                'for_attachment' => true
            ]);
            
            $exportController = new ExportEnrolleesController();
            $csvResponse = $exportController->exportEnrolleesForAttachment($exportRequest);
            
            if ($csvResponse->getStatusCode() !== 200) {
                $this->error("Failed to generate CSV. Status: " . $csvResponse->getStatusCode());
                return 1;
            }
            
            $csvContent = $csvResponse->getContent();
            $csvSize = strlen($csvContent);
            
            $this->info("✅ CSV generated successfully!");
            $this->info("   Size: {$csvSize} bytes");
            $this->info("   Lines: " . (substr_count($csvContent, "\n") + 1));
            $this->newLine();
            
            // Step 2: Save CSV to temporary file
            $this->info("Step 2: Creating temporary CSV file...");
            
            $tempPath = tempnam(sys_get_temp_dir(), 'maxicare_test_');
            $csvPath = $tempPath . '.csv';
            file_put_contents($csvPath, $csvContent);
            
            $this->info("✅ CSV saved to: {$csvPath}");
            $this->newLine();
            
            // Step 3: Create notification
            $this->info("Step 3: Creating test notification...");
            
            $notification = Notification::create([
                'enrollment_id' => $enrollment->id,
                'notification_type' => 'CUSTOM MAXICARE DECLARATION',
                'to' => 'markimperial@llibi.com',
                'subject' => 'Maxicare Declaration Report - ' . $enrollment->title,
                'title' => 'Maxicare Declaration Report',
                'message' => 'Dear Recipient,\n\nPlease find attached the maxicare declaration report for ' . $enrollment->title . '.\n\nThis report contains all APPROVED enrollees with their complete information.\n\nBest regards,\nHR Team',
                'is_html' => false,
                'is_read' => false
            ]);
            
            $this->info("✅ Created notification ID: {$notification->id}");
            $this->newLine();
            
            // Step 4: Send email manually using SendNotificationController
            $this->info("Step 4: Sending email with attachment...");
            $this->info("Recipient: markimperial@llibi.com");
            $this->info("Attachment: maxicare_declaration.csv");
            
            // Create request with manual CSV attachment
            $sendRequest = new Request([
                'notification_id' => $notification->id,
                'to' => 'markimperial@llibi.com',
                'csv_attachment' => [
                    'path' => $csvPath,
                    'name' => 'maxicare_declaration_' . date('Ymd_His') . '.csv',
                    'has_data' => true,
                    'data_rows' => substr_count($csvContent, "\n")
                ]
            ]);
            
            $controller = new SendNotificationController();
            $response = $controller->send($sendRequest);
            
            // Get the response content
            $responseData = json_decode($response->getContent(), true);
            
            if ($responseData['success'] ?? false) {
                $this->info("✅ SUCCESS: Email sent successfully!");
                $this->info("Details: " . ($responseData['message'] ?? 'Email sent'));
            } else {
                $this->error("❌ FAILED: " . ($responseData['message'] ?? 'Unknown error'));
                $this->line("Response: " . json_encode($responseData, JSON_PRETTY_PRINT));
            }
            
            // Clean up
            $this->newLine();
            $this->info("Cleaning up...");
            
            if (file_exists($csvPath)) {
                unlink($csvPath);
                $this->info("✅ Deleted temporary CSV file");
            }
            
            if (file_exists($tempPath)) {
                unlink($tempPath);
                $this->info("✅ Deleted temporary file");
            }
            
            $notification->delete();
            $this->info("✅ Deleted test notification");
            
        } catch (\Exception $e) {
            $this->error("❌ EXCEPTION: " . $e->getMessage());
            $this->error("File: " . $e->getFile());
            $this->error("Line: " . $e->getLine());
            
            // Clean up even if there was an error
            if (isset($csvPath) && file_exists($csvPath)) {
                unlink($csvPath);
            }
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }
            if (isset($notification) && $notification->exists) {
                $notification->delete();
            }
            
            return 1;
        }
        
        $this->newLine();
        $this->info("✅ Test completed successfully!");
        $this->info("Check markimperial@llibi.com for the maxicare declaration email with CSV attachment.");
        
        return 0;
    }
}