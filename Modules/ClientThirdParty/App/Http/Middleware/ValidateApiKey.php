<?php

namespace Modules\ClientThirdParty\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ClientThirdParty\App\Models\ApiCredential;
use Symfony\Component\HttpFoundation\Response;

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
            return response()->json([
                'success' => false,
                'message' => 'Missing API key. Provide it in the X-API-Key header.',
            ], 401);
        }

        /** @var ApiCredential|null $credential */
        $credential = ApiCredential::where('api_key', $apiKey)->first();

        if (!$credential) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key.',
            ], 401);
        }

        if (!$credential->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'API key is inactive or expired.',
            ], 403);
        }

        // IP whitelist check
        if (!$credential->allowsIp($request->ip())) {
            return response()->json([
                'success' => false,
                'message' => 'Access from your IP address is not permitted.',
            ], 403);
        }

        // Permission check (if the middleware was given a required permission)
        if ($permission && !$credential->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => "API key does not have the required permission: {$permission}",
            ], 403);
        }

        // Record usage (non-blocking)
        $credential->recordUsage();

        // Attach the credential to the request so downstream controllers can use it
        $request->attributes->set('api_credential', $credential);

        return $next($request);
    }
}
