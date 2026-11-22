<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sms' => [
        'enabled' => env('SMS_ENABLED', true),
        'url' => env('SMS_GATEWAY_URL', 'http://46.250.238.187/goip/sendsms/'),
        'username' => env('SMS_GATEWAY_USERNAME', 'root'),
        'password' => env('SMS_GATEWAY_PASSWORD', 'LACSONSMS'),
        'timeout' => env('SMS_GATEWAY_TIMEOUT', 20),
    ],

    'clientcare' => [
        'base_url' => env('CLIENTCARE_API_BASE_URL', 'https://clientcare-api.llibi.app/api/portal'),
        'connect_timeout' => env('CLIENTCARE_CONNECT_TIMEOUT', 10),
        'timeout' => env('CLIENTCARE_API_TIMEOUT', 30),
    ],

];
