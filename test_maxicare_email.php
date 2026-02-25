<?php
/**
 * Test script for sending maxicare declaration email to markimperial@llibi.com
 * This script demonstrates the complete workflow of sending a maxicare declaration
 * with CSV attachment using the existing notification system.
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Http\Controllers\SendNotificationController;
use Modules\ClientMasterlist\App\Models\Notification;
use Modules\ClientMasterlist\App\Models\Enrollment;

// Initialize Laravel application
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Request::capture();
$kernel->bootstrap();

echo "Testing Maxicare Declaration Email Sending\n";
echo "==========================================\n\n";

try {
    // Find the first available enrollment (for testing purposes)
    $enrollment = Enrollment::first();
    
    if (!$enrollment) {
        echo "Error: No enrollments found in database for testing.\n";
        exit(1);
    }
    
    echo "Using enrollment ID: {$enrollment->id}\n";
    echo "Enrollment name: {$enrollment->enrollment_name}\n\n";
    
    // Create or find a test notification for maxicare declaration
    $notification = Notification::where('notification_type', 'MAXICARE DECLARATION')
        ->where('enrollment_id', $enrollment->id)
        ->first();
    
    if (!$notification) {
        // Create a test notification for maxicare declaration
        $notification = new Notification([
            'enrollment_id' => $enrollment->id,
            'notification_type' => 'MAXICARE DECLARATION',
            'to' => 'markimperial@llibi.com',
            'subject' => 'Maxicare Declaration - {{enrollment_name}}',
            'title' => 'Maxicare Declaration Report',
            'message' => 'Please find attached the maxicare declaration report for {{enrollment_name}}.',
            'is_html' => false,
            'is_read' => false
        ]);
        $notification->save();
        echo "Created test notification ID: {$notification->id}\n\n";
    } else {
        echo "Using existing notification ID: {$notification->id}\n\n";
    }
    
    // Create test request for sending the maxicare declaration
    $testRequest = new Request([
        'notification_id' => $notification->id,
        'to' => 'markimperial@llibi.com',
        'csv_attachment' => [
            'maxicare_customized_column' => 'MAXI-SCVP', // Use MAXI-SCVP for maxicare declarations
            'enrollment_id' => $enrollment->id,
            'enrollment_status' => 'APPROVED', // Focus on approved enrollees
            'export_enrollment_type' => 'REGULAR',
            'is_renewal' => false,
            'with_dependents' => true,
            'date_from' => null, // Include all dates
            'date_to' => null,
            'columns' => [] // Use default columns
        ]
    ]);
    
    echo "Preparing to send maxicare declaration email with CSV attachment...\n";
    echo "Recipient: markimperial@llibi.com\n";
    echo "Export Type: MAXI-SCVP\n";
    echo "Enrollment Status Filter: APPROVED\n\n";
    
    // Initialize the SendNotificationController
    $controller = new SendNotificationController();
    
    // Send the notification
    echo "Sending email...\n";
    $response = $controller->send($testRequest);
    
    // Get the response content
    $responseData = json_decode($response->getContent(), true);
    
    if ($responseData['success'] ?? false) {
        echo "✅ SUCCESS: Maxicare declaration email sent successfully!\n";
        echo "Details: " . ($responseData['message'] ?? 'Email sent') . "\n";
        
        if (isset($responseData['csv_info'])) {
            echo "CSV Info:\n";
            echo "- File: " . ($responseData['csv_info']['filename'] ?? 'N/A') . "\n";
            echo "- Rows: " . ($responseData['csv_info']['data_rows'] ?? 'N/A') . "\n";
        }
    } else {
        echo "❌ FAILED: " . ($responseData['message'] ?? 'Unknown error') . "\n";
        echo "Response: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";