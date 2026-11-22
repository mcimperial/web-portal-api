<?php

namespace Modules\ClientMobileApp\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ClientMobileApp\App\Models\MobileUser;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof MobileUser && $user->is_active,
            401,
            'Mobile authentication required.'
        );

        return $next($request);
    }
}
