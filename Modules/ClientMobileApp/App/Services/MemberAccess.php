<?php

namespace Modules\ClientMobileApp\App\Services;

use Illuminate\Http\Request;
use Modules\ClientMobileApp\App\Models\MobileUser;
use Modules\ClientMobileApp\App\Models\Masterlist;

class MemberAccess
{
    public static function resolve(Request $request, ?string $requestedMemberId = null): ?Masterlist
    {
        $user = $request->user();

        if (! $user instanceof MobileUser) {
            return null;
        }

        $linkedMemberId = $user->member_id;

        if ($linkedMemberId !== null) {
            if ($requestedMemberId !== null && trim($requestedMemberId) !== $linkedMemberId) {
                return null;
            }

            return Masterlist::query()
                ->select(Masterlist::PUBLIC_COLUMNS)
                ->where('member_id', $linkedMemberId)
                ->first();
        }

        $email = strtolower(trim($user->email));

        if ($email === '') {
            return null;
        }

        $query = Masterlist::query()
            ->select(Masterlist::PUBLIC_COLUMNS)
            ->whereRaw('LOWER(email) = ?', [$email]);

        if ($requestedMemberId !== null) {
            $query->where('member_id', trim($requestedMemberId));
        }

        return $query->first();
    }

    public static function link(Request $request, string $memberId, string $birthDate): ?Masterlist
    {
        $user = $request->user();

        if (! $user instanceof MobileUser) {
            return null;
        }

        $member = Masterlist::query()
            ->select(['member_id'])
            ->where('member_id', trim($memberId))
            ->whereDate('birth_date', $birthDate)
            ->first();

        if ($member) {
            $user->forceFill(['member_id' => $member->member_id])->save();
        }

        return $member;
    }
}
