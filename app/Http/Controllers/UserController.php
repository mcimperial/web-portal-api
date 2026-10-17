<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles.permissions'); // Eager load roles and their permissions

        //$user->load('hasEnrollmentRole');
        $user->enrollment_ids = method_exists($user, 'enrollmentIds') ? $user->enrollmentIds() : [];
        $user->is_active = (bool) ($user->is_active ?? true);
        $user->effective_permissions = $user->roles
            ->flatMap(fn($role) => $role->permissions)
            ->pluck('slug')
            ->filter()
            ->unique()
            ->values();

        return response()->json($user);
    }
}
