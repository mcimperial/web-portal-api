<?php

namespace Modules\DonationDrive\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\DonationDrive\App\Models\DonationDonor;
use Modules\DonationDrive\App\Models\DonationDrive;

class DonorAdminManagementController extends Controller
{
    public function __construct(private AdminDonationDriveController $management)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeDonorAdmin($request);

        return $this->management->index($request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeDonorAdmin($request);

        return $this->management->store($request);
    }

    public function status(Request $request, DonationDrive $drive): JsonResponse
    {
        $this->authorizeDonorAdmin($request);

        return $this->management->status($request, $drive);
    }

    public function closure(Request $request, DonationDrive $drive): JsonResponse
    {
        $this->authorizeDonorAdmin($request);

        return $this->management->closure($request, $drive);
    }

    public function uploadDocument(Request $request, DonationDrive $drive): JsonResponse
    {
        $this->authorizeDonorAdmin($request);

        return $this->management->uploadDocument($request, $drive);
    }

    public function donors(Request $request): JsonResponse
    {
        $this->authorizeDonorAdmin($request);

        return $this->management->donors($request);
    }

    private function authorizeDonorAdmin(Request $request): void
    {
        $donor = $request->user();
        abort_unless($donor instanceof DonationDonor && $donor->is_admin, 403, 'Donation administrator access is required.');
    }
}
