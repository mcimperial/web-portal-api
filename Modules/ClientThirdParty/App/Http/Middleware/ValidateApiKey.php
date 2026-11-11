<?php

namespace Modules\ClientThirdParty\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ClientThirdParty\App\Models\ApiCredential;
use Modules\ClientThirdParty\App\Models\ApiUsageLog;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ValidateApiKey
{
    /**
     * Handle an incoming request.
     *
     * Reads the API key from the `X-API-Key` request header and validates it
     * against the ct_api_credentials table.
     *
     * Optionally pass a required permission string via the middleware parameter:
     *   Route::middleware('api.key:enrollment:read')
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (empty($apiKey)) {
            $this->logUsageAttempt($request, null, null, 401, 'failed', 'missing_api_key');
            return response()->json([
                'success' => false,
                'message' => 'Missing API key. Provide it in the X-API-Key header.',
            ], 401);
        }

        /** @var ApiCredential|null $credential */
        $credential = ApiCredential::where('api_key', $apiKey)->first();

        if (!$credential) {
            $this->logUsageAttempt($request, null, $apiKey, 401, 'failed', 'invalid_api_key');
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
            ], 401);
        }

        if (!$credential->isValid()) {
            $this->logUsageAttempt($request, $credential, $apiKey, 403, 'failed', 'inactive_or_expired_key');
            return response()->json([
                'success' => false,
                'message' => 'API key is inactive or expired.',
            ], 403);
        }

        // IP whitelist check
        if (!$credential->allowsIp($request->ip())) {
            $this->logUsageAttempt($request, $credential, $apiKey, 403, 'failed', 'ip_not_allowed');
            return response()->json([
                'success' => false,
                'message' => 'Access from your IP address is not permitted.',
            ], 403);
        }

        // Permission check (if the middleware was given a required permission)
        if ($permission && !$credential->hasPermission($permission)) {
            $this->logUsageAttempt($request, $credential, $apiKey, 403, 'failed', 'missing_permission:' . $permission);
            return response()->json([
                'success' => false,
                'message' => "API key does not have the required permission: {$permission}",
            ], 403);
        }

        // Record usage (non-blocking)
        $credential->recordUsage();

        // Attach the credential to the request so downstream controllers can use it
        $request->attributes->set('api_credential', $credential);

        $response = $next($request);

        $this->logUsageAttempt(
            $request,
            $credential,
            $apiKey,
            $response->getStatusCode(),
            $response->getStatusCode() < 400 ? 'success' : 'failed',
            $response->getStatusCode() < 400 ? null : 'downstream_error'
        );

        return $response;
    }

    private function logUsageAttempt(
        Request $request,
        ?ApiCredential $credential,
        ?string $apiKey,
        int $responseStatus,
        string $status,
        ?string $failureReason
    ): void {
        try {
            $route = $request->route();
            $routeName = $route?->getName();
            $process = $routeName ?: $request->path();

            ApiUsageLog::create([
                'api_credential_id' => $credential?->id,
                'api_key_last4' => $this->maskApiKey($apiKey),
                'process' => $process,
                'route_name' => $routeName,
                'method' => strtoupper($request->method()),
                'endpoint' => '/' . ltrim($request->path(), '/'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'response_status' => $responseStatus,
                'status' => $status,
                'failure_reason' => $failureReason,
                'metadata' => [
                    'request_uri' => '/' . ltrim($request->getRequestUri(), '/'),
                    'query_string' => (string) $request->server('QUERY_STRING', ''),
                    'query' => $request->query(),
                ],
            ]);
        } catch (Throwable $e) {
        }
    }

    private function maskApiKey(?string $apiKey): ?string
    {
        if (!$apiKey) {
            return null;
        }

        $suffix = substr($apiKey, -4);

        return $suffix ? ('***' . $suffix) : null;
    }
}
