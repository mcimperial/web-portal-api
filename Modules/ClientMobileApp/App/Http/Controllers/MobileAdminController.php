<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use Modules\ClientMobileApp\App\Models\MobileLegalDocument;
use Modules\ClientMobileApp\App\Models\MobilePopup;
use Modules\ClientMobileApp\App\Models\MobileUser;

class MobileAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            $user = $request->user();
            abort_unless($user instanceof User && $user->is_active && $user->hasRole('admin'), 403, 'Admin access required.');

            return $next($request);
        });
    }

    public function popups(): JsonResponse
    {
        return response()->json(['data' => MobilePopup::with('messages')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function storePopup(Request $request): JsonResponse
    {
        $data = $this->validatePopup($request);
        $popup = DB::connection('mysql')->transaction(function () use ($data) {
            $messages = $data['messages'];
            unset($data['messages']);
            $popup = MobilePopup::create($data);
            $popup->messages()->createMany($messages);

            return $popup->load('messages');
        });

        return response()->json(['data' => $popup], 201);
    }

    public function updatePopup(Request $request, MobilePopup $popup): JsonResponse
    {
        $data = $this->validatePopup($request);
        DB::connection('mysql')->transaction(function () use ($popup, $data) {
            $messages = $data['messages'];
            unset($data['messages']);
            $popup->update($data);
            $existingIds = $popup->messages()->pluck('id')->all();
            $retainedIds = [];
            foreach ($messages as $message) {
                if (isset($message['id'])) {
                    abort_unless(in_array($message['id'], $existingIds), 422, 'Invalid popup message.');
                    $popup->messages()->whereKey($message['id'])->update($message);
                    $retainedIds[] = $message['id'];
                } else {
                    $retainedIds[] = $popup->messages()->create($message)->id;
                }
            }
            $popup->messages()->whereNotIn('id', $retainedIds)->delete();
        });

        return response()->json(['data' => $popup->load('messages')]);
    }

    public function deletePopup(MobilePopup $popup): JsonResponse
    {
        $popup->delete();

        return response()->json(['message' => 'Popup deleted.']);
    }

    private function validatePopup(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'show_decline_button' => ['required', 'boolean'],
            'accept_label' => ['nullable', 'string', 'max:50'],
            'decline_label' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.id' => ['sometimes', 'integer', 'distinct'],
            'messages.*.title' => ['nullable', 'string', 'max:255'],
            'messages.*.html_content' => ['nullable', 'string', 'required_without:messages.*.image_url'],
            'messages.*.image_url' => ['nullable', 'url', 'max:2048', 'required_without:messages.*.html_content'],
            'messages.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);
    }

    public function legalDocuments(): JsonResponse
    {
        return response()->json([
            'data' => MobileLegalDocument::query()
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function updateLegalDocument(Request $request, string $documentKey): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'html_content' => ['required', 'string', 'max:100000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $document = DB::connection('mysql')->transaction(function () use ($documentKey, $data) {
            $document = MobileLegalDocument::where('document_key', $documentKey)->firstOrFail();
            $document->update($data);
            $document->increment('version');

            return $document->fresh();
        });

        return response()->json(['data' => $document]);
    }

    public function configuration(): JsonResponse
    {
        $config = MobileConfiguration::where('config_key', 'loa_request_backend')->firstOrFail();

        return response()->json(['data' => $config, 'has_api_key' => filled($config->api_key)]);
    }

    public function updateConfiguration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'config_value' => ['required', Rule::in(['system', 'third_party'])],
            'api_header' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D'],
            'api_url' => ['nullable', 'url:http,https', 'max:255'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        $config = MobileConfiguration::where('config_key', 'loa_request_backend')->firstOrFail();
        $key = array_key_exists('api_key', $data) ? $data['api_key'] : $config->api_key;
        if ($data['config_value'] === 'third_party' && (blank($data['api_header'] ?? null) || blank($data['api_url'] ?? null) || blank($key))) {
            return response()->json(['message' => 'Third-party mode requires an API URL, header, and key.'], 422);
        }
        $config->update($data);

        return response()->json(['data' => $config->fresh(), 'has_api_key' => filled($config->api_key)]);
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $query = MobileUser::query()->select(['id', 'uuid', 'name', 'email', 'contact_number', 'member_id', 'is_active', 'email_verified_at', 'created_at']);
        if (! empty($data['search'])) {
            $term = '%'.$data['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('member_id', 'like', $term));
        }

        return response()->json($query->orderByDesc('id')->paginate(25));
    }

    public function updateUser(Request $request, MobileUser $mobileUser): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('mysql.mobile_users', 'email')->ignore($mobileUser->id)],
            'contact_number' => ['nullable', 'string', 'regex:/^09[0-9]{9}$/'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($data['email'] !== $mobileUser->email) {
            $data['email_verified_at'] = null;
        }
        DB::connection('mysql')->transaction(function () use ($data, $mobileUser) {
            if (! $data['is_active']) {
                $mobileUser->tokens()->delete();
            }
            $mobileUser->update($data);
        });

        return response()->json(['data' => $mobileUser]);
    }

    public function revokeUser(MobileUser $mobileUser): JsonResponse
    {
        $mobileUser->tokens()->delete();

        return response()->json(['message' => 'Sessions revoked.']);
    }

    public function deleteUser(MobileUser $mobileUser): JsonResponse
    {
        DB::connection('mysql')->transaction(function () use ($mobileUser) {
            $mobileUser->tokens()->delete();
            $mobileUser->delete();
        });

        return response()->json(['message' => 'Mobile user deleted.']);
    }

    public function apis(): JsonResponse
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/mobile/'))
            ->map(fn ($route) => [
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'path' => '/'.$route->uri(),
                'authentication' => in_array('auth:mobile', $route->gatherMiddleware(), true) ? 'Mobile user' : 'Public',
            ])->values();

        return response()->json(['data' => $routes]);
    }
}
