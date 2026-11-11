<?php

namespace Modules\ClientThirdParty\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\ClientThirdParty\App\Models\ApiCredential;

class ApiCredentialController extends Controller
{
    // -----------------------------------------------------------------------
    // List all credentials (admin)
    // -----------------------------------------------------------------------
    public function index(): JsonResponse
    {
        $credentials = ApiCredential::orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data'    => $credentials,
            'total'   => $credentials->count(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Show a single credential
    // -----------------------------------------------------------------------
    public function show(int $id): JsonResponse
    {
        $credential = ApiCredential::findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $credential,
        ]);
    }

    // -----------------------------------------------------------------------
    // Create / issue a new API key
    // -----------------------------------------------------------------------
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'client_name'   => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|in:enrollment:read,principal:list:read,principal:read,dependent:read',
            'allowed_ips'   => 'nullable|array',
            'allowed_ips.*' => 'ip',
            'params'        => 'nullable|array',
            'params.enrollment_titles' => 'nullable|array',
            'params.enrollment_titles.*' => 'string|max:255',
            'expires_at'    => 'nullable|date',
            'notes'         => 'nullable|string',
            'status'        => 'nullable|in:ACTIVE,INACTIVE',
        ]);

        if (array_key_exists('params', $validated)) {
            $validated['params'] = $this->normalizeParams($validated['params']);
        }

        // Store the plain-text secret temporarily to show it once
        $plainSecret = ApiCredential::generateApiSecret();

        $credential = ApiCredential::create(array_merge($validated, [
            'api_secret' => $plainSecret,
            'status'     => $validated['status'] ?? 'ACTIVE',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'API credential created. Store the api_secret now — it will not be shown again.',
            'data'    => array_merge($credential->toArray(), [
                'api_secret' => $plainSecret, // plain-text shown only on creation
            ]),
        ], 201);
    }

    // -----------------------------------------------------------------------
    // Update a credential (name, permissions, status, etc.)
    // -----------------------------------------------------------------------
    public function update(Request $request, int $id): JsonResponse
    {
        $credential = ApiCredential::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'client_name'   => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|in:enrollment:read,principal:list:read,principal:read,dependent:read',
            'allowed_ips'   => 'nullable|array',
            'allowed_ips.*' => 'ip',
            'params'        => 'sometimes|nullable|array',
            'params.enrollment_titles' => 'nullable|array',
            'params.enrollment_titles.*' => 'string|max:255',
            'expires_at'    => 'nullable|date',
            'notes'         => 'nullable|string',
            'status'        => 'nullable|in:ACTIVE,INACTIVE,REVOKED',
        ]);

        if (array_key_exists('params', $validated)) {
            $validated['params'] = $this->normalizeParams($validated['params']);
        }

        $credential->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'API credential updated.',
            'data'    => $credential->fresh(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Revoke (soft-delete) a credential
    // -----------------------------------------------------------------------
    public function destroy(Request $request, int $id): JsonResponse
    {
        $credential = ApiCredential::findOrFail($id);

        if (Schema::hasColumn($credential->getTable(), 'deleted_by')) {
            $credential->deleted_by = $request->user()?->id;
            $credential->save();
        }

        $credential->delete();

        return response()->json([
            'success' => true,
            'message' => 'API credential revoked.',
        ]);
    }

    // -----------------------------------------------------------------------
    // Regenerate API key for an existing credential
    // -----------------------------------------------------------------------
    public function regenerateKey(int $id): JsonResponse
    {
        $credential = ApiCredential::findOrFail($id);

        $newKey    = ApiCredential::generateApiKey();
        $newSecret = ApiCredential::generateApiSecret();

        $credential->update([
            'api_key'    => $newKey,
            'api_secret' => $newSecret,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'API key regenerated. Store the new api_secret — it will not be shown again.',
            'data'    => array_merge($credential->fresh()->toArray(), [
                'api_key'    => $newKey,
                'api_secret' => $newSecret,  // plain-text shown only here
            ]),
        ]);
    }

    // -----------------------------------------------------------------------
    // Usage logs for a specific credential
    // -----------------------------------------------------------------------
    public function usageLogs(Request $request, int $id): JsonResponse
    {
        $credential = ApiCredential::findOrFail($id);

        $validated = $request->validate([
            'status' => 'nullable|in:success,failed',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $logsQuery = $credential->usageLogs()->orderByDesc('created_at');

        if (!empty($validated['status'])) {
            $logsQuery->where('status', $validated['status']);
        }

        $logs = $logsQuery->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    private function normalizeParams(?array $params): ?array
    {
        if ($params === null) {
            return null;
        }

        $enrollmentTitles = collect($params['enrollment_titles'] ?? [])
            ->map(fn ($title) => trim((string) $title))
            ->filter()
            ->unique(fn ($title) => mb_strtolower($title))
            ->values()
            ->all();

        if (empty($enrollmentTitles)) {
            return null;
        }

        return [
            'enrollment_titles' => $enrollmentTitles,
        ];
    }
}
