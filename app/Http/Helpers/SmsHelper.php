<?php

namespace App\Http\Helpers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsHelper
{
    public static function send(string $mobile, string $message): array
    {
        $config = config('services.sms');

        if (! ($config['enabled'] ?? false)) {
            return self::failure('SMS notifications are disabled.');
        }

        $cleanedMobile = self::cleanMobileNumber($mobile);
        if ($cleanedMobile === null) {
            return self::failure('Invalid mobile number. Expected 09XXXXXXXXX, 639XXXXXXXXX, or 9XXXXXXXXX.');
        }

        if (blank($config['url'] ?? null) || blank($config['username'] ?? null) || blank($config['password'] ?? null)) {
            return self::failure('SMS gateway is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(8)
                ->timeout((int) ($config['timeout'] ?? 20))
                ->post($config['url'], [
                    'auth' => [
                        'username' => $config['username'],
                        'password' => $config['password'],
                    ],
                    'provider' => 'SIMNETWORK',
                    'number' => $cleanedMobile,
                    'content' => $message,
                ]);
        } catch (ConnectionException $exception) {
            Log::error('SMS gateway connection failed.', [
                'mobile' => self::maskMobileNumber($cleanedMobile),
                'error' => $exception->getMessage(),
            ]);

            return self::failure($exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('SMS send failed unexpectedly.', [
                'mobile' => self::maskMobileNumber($cleanedMobile),
                'error' => $exception->getMessage(),
            ]);

            return self::failure($exception->getMessage());
        }

        $body = $response->json();
        $accepted = $response->successful() && ! self::responseIndicatesFailure($body);

        if (! $accepted) {
            $error = self::gatewayError($body) ?: "SMS gateway returned HTTP {$response->status()}.";
            Log::warning('SMS gateway rejected the message.', [
                'mobile' => self::maskMobileNumber($cleanedMobile),
                'http_code' => $response->status(),
                'error' => $error,
            ]);

            return [
                'success' => false,
                'http_code' => $response->status(),
                'error' => $error,
                'response' => $body,
            ];
        }

        Log::info('SMS sent through gateway.', [
            'mobile' => self::maskMobileNumber($cleanedMobile),
            'http_code' => $response->status(),
        ]);

        return [
            'success' => true,
            'http_code' => $response->status(),
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

    private static function maskMobileNumber(string $mobile): string
    {
        return substr($mobile, 0, 4).str_repeat('*', 5).substr($mobile, -2);
    }

    private static function failure(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'response' => null,
        ];
    }
}
