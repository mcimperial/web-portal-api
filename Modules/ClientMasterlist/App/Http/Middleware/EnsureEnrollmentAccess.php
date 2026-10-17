<?php

namespace Modules\ClientMasterlist\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Services\Enrollments\EnrollmentAccessService;
use Symfony\Component\HttpFoundation\Response;

class EnsureEnrollmentAccess
{
    public function __construct(
        private readonly EnrollmentAccessService $accessService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $enrollmentId = $request->route('id')
            ?? $request->route('enrollment')
            ?? $request->input('enrollment_id');

        $enrollment = Enrollment::findOrFail($enrollmentId);

        if (! $this->accessService->hasAccess($request->user(), $enrollment)) {
            return response()->json([
                'message' => 'Unauthorized access to this enrollment',
            ], 403);
        }

        return $next($request);
    }
}
