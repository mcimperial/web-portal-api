<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\ClientMobileApp\App\Models\MobileCallbackRequest;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use Modules\ClientMobileApp\App\Services\ClientCareAuthenticationException;
use Modules\ClientMobileApp\App\Services\ClientCareLoaApiService;

class CallbackRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return response()->json(
            MobileCallbackRequest::where('mobile_user_id', $request->user()->id)
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 10))
        );
    }

    public function store(Request $request, ClientCareLoaApiService $clientCareApi): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! Str::isUuid($key)) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.'], 422);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'regex:/^09[0-9]{9}$/'],
            'landline' => ['nullable', 'string', 'max:40'],
            'request' => ['required', 'string', 'max:2000'],
            'alt_email' => ['nullable', 'email', 'max:255'],
            'alt_landline' => ['nullable', 'string', 'max:40'],
            'alt_mobile' => ['nullable', 'string', 'regex:/^09[0-9]{9}$/'],
        ]);
        $attributes = [
            'requestor_name' => $request->user()->name,
            'email' => $validated['email'],
            'mobile' => $validated['mobile'],
            'landline' => $validated['landline'] ?? null,
            'request_message' => $validated['request'],
            'alt_email' => $validated['alt_email'] ?? null,
            'alt_landline' => $validated['alt_landline'] ?? null,
            'alt_mobile' => $validated['alt_mobile'] ?? null,
        ];
        $hash = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
        $existing = MobileCallbackRequest::where('mobile_user_id', $request->user()->id)
            ->where('idempotency_key', $key)->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        $configuration = MobileConfiguration::where('config_key', 'loa_request_backend')->first();
        if (! $clientCareApi->isConfigured($configuration, config('services.clientcare.callback_url'))) {
            Log::error('Mobile callback API credentials or URL are not configured.');

            return response()->json([
                'message' => 'Callback requests are not configured. Your request was not submitted; contact support.',
            ], 503);
        }

        $callback = MobileCallbackRequest::firstOrCreate(
            ['mobile_user_id' => $request->user()->id, 'idempotency_key' => $key],
            [...$attributes, 'request_hash' => $hash, 'status' => 'processing']
        );
        if (! $callback->wasRecentlyCreated) {
            return $this->replay($callback, $hash);
        }

        try {
            $response = $clientCareApi->submitCallback($configuration, [
                'email' => $callback->email,
                'mobile' => $callback->mobile,
                'landline' => $callback->landline,
                'platform' => 'mobile',
                'data' => [
                    'request' => $callback->request_message,
                    'alt_email' => $callback->alt_email,
                    'alt_landline' => $callback->alt_landline,
                    'alt_mobile' => $callback->alt_mobile,
                ],
                'user' => ['provider_id' => null, 'keyword' => $callback->requestor_name],
            ]);
        } catch (ClientCareAuthenticationException $exception) {
            $callback->update(['status' => 'failed']);
            Log::warning('Mobile callback API rejected the configured credentials.', ['callback_id' => $callback->id]);

            return $this->result($callback);
        } catch (ConnectionException|RequestException $exception) {
            $status = $exception instanceof RequestException ? $exception->response->status() : null;
            $callback->update(['status' => $status !== null && $status >= 400 && $status < 500 ? 'failed' : 'unconfirmed']);
            Log::warning('Mobile callback submission was not confirmed.', ['callback_id' => $callback->id, 'status' => $status]);

            return $this->result($callback);
        }

        $rejected = ! $response->successful()
            || $response->json('success') === false
            || in_array($response->json('status'), ['error', 'failed', 'rejected'], true);
        $callback->update(['status' => $rejected ? 'failed' : 'submitted']);
        if ($rejected) {
            Log::warning('Mobile callback API returned a rejection.', ['callback_id' => $callback->id]);
        }

        return $this->result($callback, true);
    }

    private function replay(MobileCallbackRequest $callback, string $hash): JsonResponse
    {
        if (! hash_equals($callback->request_hash, $hash)) {
            return response()->json(['message' => 'This submission key was already used for a different request.'], 409);
        }

        return $this->result($callback);
    }

    private function result(MobileCallbackRequest $callback, bool $created = false): JsonResponse
    {
        [$message, $status] = match ($callback->status) {
            'submitted' => ['Callback request submitted successfully.', $created ? 201 : 200],
            'failed' => ['The callback provider rejected this request. Contact support for assistance.', 502],
            'unconfirmed' => ['Callback submission could not be confirmed. Do not resubmit; contact support.', 502],
            default => ['This callback request is still processing. Do not resubmit; check your request history.', 409],
        };

        return response()->json(['message' => $message, 'request' => $callback], $status);
    }
}
