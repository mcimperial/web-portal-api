<?php

namespace Modules\ClientMasterlist\App\Services\Enrollments;

use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\EnrollmentRole;

class EnrollmentAccessService
{
    public function hasAccess($user, Enrollment $enrollment): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (!$user->hasModulePermission('enrollments', 'view')) {
            return false;
        }

        return EnrollmentRole::query()
            ->where('user_id', $user->id)
            ->where('enrollment_id', $enrollment->id)
            ->exists();
    }

    public function isAdmin($user): bool
    {
        return $user->isSuperAdmin();
    }

    public function accessPayload($user): array
    {
        $isAdmin = $this->isAdmin($user);

        return [
            'can_edit' => $isAdmin,
            'can_view' => true,
            'is_admin' => $isAdmin,
        ];
    }
}
