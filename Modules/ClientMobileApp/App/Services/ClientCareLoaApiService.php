<?php

namespace Modules\ClientMobileApp\App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use RuntimeException;

class ClientCareLoaApiService
{
    public function isConfigured(?MobileConfiguration $configuration, ?string $baseUrl = null): bool
    {
        $apiKey = $configuration?->api_key;
        $apiHeader = $configuration?->api_header;
        $baseUrl ??= $configuration?->api_url;

        return is_string($apiKey)
            && trim($apiKey) !== ''
            && is_string($apiHeader)
            && preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $apiHeader) === 1
            && is_string($baseUrl)
            && filter_var($baseUrl, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    public function submitConsultation(MobileConfiguration $configuration, array $payload): Response
    {
        return $this->verifyResponse(
            $this->request($configuration)
                ->post($this->endpoint($configuration, 'consultation-request'), $payload)
        );
    }

    public function submitLaboratory(MobileConfiguration $configuration, array $payload, string $fileContents, string $fileName): Response
    {
        return $this->verifyResponse(
            $this->request($configuration)
                ->attach('files[]', $fileContents, $fileName)
                ->post($this->endpoint($configuration, 'laboratory-request'), $payload)
        );
    }

    public function submitCallback(MobileConfiguration $configuration, array $payload): Response
    {
        $url = config('services.clientcare.callback_url');

        return $this->verifyResponse(
            $this->request($configuration, $url)->withoutRedirecting()->asJson()->post($url, $payload)
        );
    }

    private function verifyResponse(Response $response): Response
    {
        $response->throw();

        $message = $response->json('message');
        if (is_string($message) && strcasecmp(trim($message), 'Unauthorized') === 0) {
            throw new ClientCareAuthenticationException('ClientCare rejected the API credentials.');
        }

        return $response;
    }

    private function request(MobileConfiguration $configuration, ?string $url = null): PendingRequest
    {
        if (! $this->isConfigured($configuration, $url)) {
            throw new RuntimeException('ClientCare API configuration is missing.');
        }

        return Http::acceptJson()
            ->withHeaders([$configuration->api_header => $configuration->api_key])
            ->connectTimeout((int) config('services.clientcare.connect_timeout', 10))
            ->timeout((int) config('services.clientcare.timeout', 30));
    }

    private function endpoint(MobileConfiguration $configuration, string $path): string
    {
        return rtrim($configuration->api_url, '/').'/'.$path;
    }
}
