<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMobileApp\App\Models\AppRemaining;
use Modules\ClientMobileApp\App\Services\MemberAccess;

/**
 * Remaining benefits from llibiapp_portal.app_remaining.
 */
class ClaimsController extends Controller
{
    /**
     * Remaining consultation allowance for a member.
     */
    public function remaining(Request $request): JsonResponse
    {
        $request->validate([
            'member_id' => ['sometimes', 'string', 'max:15'],
        ]);

        $member = MemberAccess::resolve($request, $request->input('member_id'));

        if (! $member) {
            return response()->json(['message' => 'Link a valid member profile before viewing remaining benefits.'], 404);
        }

        $remaining = AppRemaining::query()
            ->where('uniquecode', $member->member_id)
            ->get(['id', 'uniquecode', 'plan', 'allow']);

        return response()->json([
            'member_id' => $member->member_id,
            'remaining' => $remaining,
        ]);
    }
}
