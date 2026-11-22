<?php

namespace Modules\ClientMobileData\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ClientMobileData\App\Models\Masterlist;
use Modules\ClientMobileData\App\Services\MemberAccess;

/**
 * Member profile & dependents, sourced from llibiapp_sync.masterlist.
 */
class MemberController extends Controller
{
    /** Return the authenticated mobile user's linked member profile. */
    public function profile(Request $request): JsonResponse
    {
        $request->validate([
            'member_id' => ['sometimes', 'string', 'max:15'],
        ]);

        $member = MemberAccess::resolve($request, $request->input('member_id'));

        if (! $member) {
            return response()->json([
                'message' => 'No member record found. Please activate your membership first.',
            ], 404);
        }

        return response()->json(['member' => $member]);
    }

    /**
     * List the member's family (same empcode + company_code),
     * excluding the resolved member record itself.
     */
    public function dependents(Request $request): JsonResponse
    {
        $request->validate([
            'member_id' => ['sometimes', 'string', 'max:15'],
        ]);

        $member = MemberAccess::resolve($request, $request->input('member_id'));

        if (! $member) {
            return response()->json([
                'message' => 'No member record found. Please activate your membership first.',
            ], 404);
        }

        $dependents = collect();

        if (! empty($member->empcode)) {
            $dependents = Masterlist::query()
                ->select(Masterlist::PUBLIC_COLUMNS)
                ->where('company_code', $member->company_code)
                ->where('empcode', $member->empcode)
                ->where('relation', '!=', 'EMPLOYEE')
                ->where('member_id', '!=', $member->member_id)
                ->orderBy('birth_date')
                ->get();
        }

        return response()->json([
            'member_id' => $member->member_id,
            'dependents' => $dependents,
        ]);
    }

    public function link(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['required', 'string', 'max:15'],
            'birth_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $member = MemberAccess::link(
            $request,
            $validated['member_id'],
            $validated['birth_date']
        );

        if (! $member) {
            return response()->json([
                'message' => 'Member ID and date of birth do not match a masterlist record.',
            ], 422);
        }

        return response()->json([
            'message' => 'Member linked successfully.',
            'member_id' => $member->member_id,
        ]);
    }
}
