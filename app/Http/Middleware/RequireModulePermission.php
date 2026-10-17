<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireModulePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is deactivated.'], 403);
        }

        if (!$user->hasModulePermission($module, $action)) {
            return response()->json([
                'message' => 'Forbidden.',
                'required_permission' => strtolower($module) . '.' . strtolower($action),
            ], 403);
        }

        return $next($request);
    }
}
