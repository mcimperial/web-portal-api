<?php

namespace App\Http\Helpers;

use Illuminate\Support\Facades\Log;

class SmsHelper
{
    /**
     * Validate and clean mobile number to 09XXXXXXXXX format
     * 
     * @param string $mobile The mobile number to clean
     * @return string|null Cleaned mobile number or null if invalid
     */
    private static function cleanMobileNumber($mobile)
    {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $mobile);

        // Handle different formats
        if (strlen($cleaned) === 11 && substr($cleaned, 0, 2) === '09') {
            // Already in 09XXXXXXXXX format
            return $cleaned;
        } elseif (strlen($cleaned) === 12 && substr($cleaned, 0, 3) === '639') {
            // Convert 639XXXXXXXXX to 09XXXXXXXXX
            return '0' . substr($cleaned, 2);
        } elseif (strlen($cleaned) === 10 && substr($cleaned, 0, 1) === '9') {
            // Convert 9XXXXXXXXX to 09XXXXXXXXX
            return '0' . $cleaned;
        }

        // Invalid format
        return null;
    }

    /**
     * Send SMS notification via GOIP gateway
     * 
     * @param string $mobile The recipient's mobile number (will be cleaned to 09XXXXXXXXX format)
     * @param string $message The SMS message content
     * @return array Response from SMS gateway
     */
    public static function send($mobile, $message)
    {
        // Check if SMS is enabled
        if (!env('SMS_ENABLED', true)) {
            Log::info('SMS notification skipped: SMS_ENABLED is false');
            return [
                'success' => false,
                'error' => 'SMS notifications are disabled',
                'response' => null
            ];
        }

        // Clean and validate mobile number
        $cleanedMobile = self::cleanMobileNumber($mobile);

        if ($cleanedMobile === null) {
            Log::error('Invalid mobile number format: ' . $mobile);
            return [
                'success' => false,
                'error' => 'Invalid mobile number format. Expected 09XXXXXXXXX, 639XXXXXXXXX, or 9XXXXXXXXX',
                'response' => null
            ];
        }

        $gatewayUrl = env('SMS_GATEWAY_URL', 'http://192.159.66.221/goip/sendsms/');
        $gatewayUsername = env('SMS_GATEWAY_USERNAME', 'root');
        $gatewayPassword = env('SMS_GATEWAY_PASSWORD', 'LACSONSMS');

        $ch = curl_init($gatewayUrl);

        $parameters = array(
            'auth' => array('username' => $gatewayUsername, 'password' => $gatewayPassword),
            'provider' => "SIMNETWORK",
            'number' => $cleanedMobile,
            'content' => $message,
        );

        Log::info('SMS Notification - Original Mobile: ' . $mobile);
        Log::info('SMS Notification - Cleaned Mobile: ' . $cleanedMobile);
        Log::info('SMS Notification - Message: ' . $message);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Set timeout to 10 seconds
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // Set connection timeout to 5 seconds

        // Receive response from server
        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error('SMS Gateway Error: ' . $curlError, [
                'mobile' => $cleanedMobile,
                'http_code' => $httpCode,
                'gateway_url' => $gatewayUrl
            ]);
            return [
                'success' => false,
                'error' => $curlError,
                'response' => null
            ];
        }

        Log::info('SMS Gateway Response: ' . $output, [
            'http_code' => $httpCode,
            'mobile' => $cleanedMobile
        ]);

        $response = json_decode($output, true);

        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $response,
            'raw_output' => $output
        ];
    }

    /**
     * Send SMS notification (instance method for backward compatibility)
     * 
     * @param string $mobile The recipient's mobile number
     * @param string $message The SMS message content
     * @return array Response from SMS gateway
     */
    public function sendSms($mobile, $message)
    {
        return self::send($mobile, $message);
    }
}
