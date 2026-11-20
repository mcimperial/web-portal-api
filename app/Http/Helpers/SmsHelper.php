<?php

namespace App\Http\Helpers;

use Illuminate\Support\Facades\Log;

class SmsHelper
{
    public static function send(string $mobile, string $message): array
    {
        $config = array_merge([
            'enabled' => true,
            'url' => 'http://46.250.238.187/goip/sendsms/',
            'username' => 'root',
            'password' => 'LACSONSMS',
            'timeout' => 20,
        ], (array) config('services.sms', []));

        if (! filter_var($config['enabled'], FILTER_VALIDATE_BOOLEAN)) {
            return self::failure('SMS notifications are disabled.');
        }

        $cleanedMobile = self::cleanMobileNumber($mobile);
        if ($cleanedMobile === null) {
            return self::failure('Invalid mobile number. Expected 09XXXXXXXXX, 639XXXXXXXXX, or 9XXXXXXXXX.');
        }

        if (blank($config['url']) || blank($config['username']) || blank($config['password'])) {
            return self::failure('GOIP SMS gateway credentials are missing.');
        }

        if (isset($config['test_result']) && is_array($config['test_result'])) {
            return $config['test_result'];
        }

        $parameters = [
            'auth' => [
                'username' => $config['username'],
                'password' => $config['password'],
            ],
            'provider' => 'SIMNETWORK',
            'number' => $cleanedMobile,
            'content' => $message,
        ];

        $handle = curl_init($config['url']);
        if ($handle === false) {
            return self::failure('Unable to initialize the GOIP SMS request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($parameters),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => max(1, (int) ($config['timeout'] ?? 20)),
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $output = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($curlError !== '' || $output === false) {
            Log::error('SMS gateway connection failed.', [
                'mobile' => self::maskMobileNumber($cleanedMobile),
                'http_code' => $httpCode,
                'error' => $curlError ?: 'Empty GOIP response.',
            ]);

            return self::failure($curlError ?: 'Empty GOIP response.', $httpCode);
        }

        $body = json_decode($output, true);
        $accepted = $httpCode >= 200
            && $httpCode < 300
            && ! self::responseIndicatesFailure($body);

        $diagnostics = self::responseDiagnostics($body);

        if (! $accepted) {
            $error = self::gatewayError($body) ?: "SMS gateway returned HTTP {$httpCode}.";
            Log::warning('SMS gateway rejected the message.', [
                'mobile' => self::maskMobileNumber($cleanedMobile),
                'http_code' => $httpCode,
                'error' => $error,
                'gateway' => $diagnostics,
            ]);

            return [
                'success' => false,
                'http_code' => $httpCode,
                'error' => $error,
                'response' => $body,
            ];
        }

        Log::info('SMS sent through gateway.', [
            'mobile' => self::maskMobileNumber($cleanedMobile),
            'http_code' => $httpCode,
            'gateway' => $diagnostics,
        ]);

        return [
            'success' => true,
            'http_code' => $httpCode,
            'response' => $body,
        ];
    }

    public function sendSms(string $mobile, string $message): array
    {
        return self::send($mobile, $message);
    }

    private static function cleanMobileNumber(string $mobile): ?string
    {
        $cleaned = preg_replace('/\D+/', '', $mobile) ?: '';

        if (strlen($cleaned) === 11 && str_starts_with($cleaned, '09')) {
            return $cleaned;
        }

        if (strlen($cleaned) === 12 && str_starts_with($cleaned, '639')) {
            return '0'.substr($cleaned, 2);
        }

        if (strlen($cleaned) === 10 && str_starts_with($cleaned, '9')) {
            return '0'.$cleaned;
        }

        return null;
    }

    private static function responseIndicatesFailure(mixed $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        foreach (['success', 'status', 'code'] as $field) {
            if (! array_key_exists($field, $body)) {
                continue;
            }

            $value = $body[$field];
            if ($value === false || in_array(strtolower((string) $value), ['false', 'error', 'failed', 'failure'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function gatewayError(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        foreach (['error', 'message', 'detail'] as $field) {
            if (isset($body[$field]) && is_string($body[$field])) {
                return $body[$field];
            }
        }

        return null;
    }

    private static function responseDiagnostics(mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        return array_intersect_key($body, array_flip([
            'success',
            'status',
            'code',
            'provider',
            'taskId',
            'task_id',
        ]));
    }

    private static function maskMobileNumber(string $mobile): string
    {
        return substr($mobile, 0, 4).str_repeat('*', 5).substr($mobile, -2);
    }

    private static function failure(string $message, ?int $httpCode = null): array
    {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $message,
            'response' => null,
        ];
    }
}
