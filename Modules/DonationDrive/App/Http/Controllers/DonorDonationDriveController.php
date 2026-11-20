<?php

namespace Modules\DonationDrive\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Modules\DonationDrive\App\Models\Donation;
use Modules\DonationDrive\App\Models\DonationDonor;
use Modules\DonationDrive\App\Models\DonationDrive;
use Modules\DonationDrive\App\Models\DonationDriveDocument;

class DonorDonationDriveController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ]);

        $firstName = $this->normalize($validated['first_name']);
        $lastName = $this->normalize($validated['last_name']);
        $normalizedName = $this->accountKey($firstName, $lastName);

        $donor = DonationDonor::firstOrCreate(
            ['normalized_name' => $normalizedName],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]
        );

        $donor->tokens()
            ->where('name', 'donation-drive')
            ->delete();

        return response()->json([
            'donor' => $donor,
            'token' => $donor->createToken('donation-drive', ['donation-drive'])->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function drives(Request $request): JsonResponse
    {
        $donor = $this->donor($request);
        $drives = DonationDrive::query()
            ->where('is_active', true)
            ->with(['documents:id,drive_id,original_name,mime_type,size'])
            ->withCount('donations')
            ->withSum('donations as total_amount', 'amount')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (DonationDrive $drive) use ($donor) {
                $drive->documents->each(function (DonationDriveDocument $document) {
                    $document->preview_url = URL::temporarySignedRoute(
                        'api.donation-drive.document.preview',
                        now()->addMinutes(30),
                        ['document' => $document->id]
                    );
                });
                $drive->my_donations = $drive->donations()
                    ->where('donor_id', $donor->id)
                    ->orderBy('cutoff')
                    ->get(['id', 'cutoff', 'amount', 'updated_at']);

                if (! $donor->is_admin) {
                    $drive->makeHidden(['donations_count', 'total_amount']);
                }

                return $drive;
            });

        return response()->json([
            'donor' => $donor->fresh(),
            'data' => $drives,
        ]);
    }

    public function donate(Request $request, DonationDrive $drive): JsonResponse
    {
        $donor = $this->donor($request);
        abort_unless($drive->is_active, 403, 'This donation drive is inactive.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'cutoff' => ['required', 'integer', 'in:1,2'],
        ]);

        $donation = Donation::firstOrNew([
            'drive_id' => $drive->id,
            'donor_id' => $donor->id,
            'cutoff' => $validated['cutoff'],
        ]);
        $previousAmount = $donation->exists ? (string) $donation->amount : null;
        $donation->amount = $validated['amount'];
        $donation->save();

        $donation->histories()->create([
            'previous_amount' => $previousAmount,
            'new_amount' => $donation->amount,
        ]);

        return response()->json([
            'message' => $previousAmount === null ? 'Donation saved.' : 'Donation updated.',
            'data' => $donation,
        ]);
    }

    public function myDonations(Request $request): JsonResponse
    {
        $donor = $this->donor($request);

        return response()->json([
            'data' => Donation::query()
                ->where('donor_id', $donor->id)
                ->with('histories')
                ->whereHas('drive', fn ($query) => $query->where('is_active', true))
                ->get(),
        ]);
    }

    public function preview(DonationDriveDocument $document)
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response(
            $document->path,
            $document->original_name,
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream'],
            'inline'
        );
    }

    public function reportDrives(Request $request): JsonResponse
    {
        $this->donorAdmin($request);

        return response()->json([
            'data' => DonationDrive::query()
                ->withCount('donations')
                ->withSum('donations as total_amount', 'amount')
                ->latest()
                ->get(),
        ]);
    }

    public function report(Request $request, DonationDrive $drive): JsonResponse
    {
        $this->donorAdmin($request);

        return response()->json($this->reportPayload($drive));
    }

    public function exportReport(Request $request, DonationDrive $drive)
    {
        $this->donorAdmin($request);
        $report = $this->reportPayload($drive);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Donor', 'Cut-off', 'Amount', 'Last Updated'], null, 'A1');

        foreach ($report['donations'] as $index => $donation) {
            $sheet->fromArray([
                trim($donation->donor->first_name . ' ' . $donation->donor->last_name),
                $donation->cutoff,
                (float) $donation->amount,
                $donation->updated_at->format('Y-m-d H:i:s'),
            ], null, 'A' . ($index + 2));
        }

        $path = storage_path('app/donation-admin-report-' . $drive->id . '.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $drive->title) . '-donations.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    private function donor(Request $request): DonationDonor
    {
        $donor = $request->user();
        abort_unless($donor instanceof DonationDonor, 403, 'A donor account is required.');

        return $donor;
    }

    private function donorAdmin(Request $request): DonationDonor
    {
        $donor = $this->donor($request);
        abort_unless($donor->is_admin, 403, 'Donation report administrator access is required.');

        return $donor;
    }

    private function reportPayload(DonationDrive $drive): array
    {
        $donations = $drive->donations()
            ->with('donor:id,first_name,last_name')
            ->orderBy('cutoff')
            ->get();

        return [
            'drive' => $drive->only(['id', 'title', 'description', 'is_active']),
            'donations' => $donations,
            'summary' => [
                'donor_count' => $donations->pluck('donor_id')->unique()->count(),
                'donation_count' => $donations->count(),
                'total_amount' => (float) $donations->sum('amount'),
                'cutoff_1_amount' => (float) $donations->where('cutoff', 1)->sum('amount'),
                'cutoff_2_amount' => (float) $donations->where('cutoff', 2)->sum('amount'),
            ],
        ];
    }

    private function normalize(string $value): string
    {
        return mb_convert_case(trim(preg_replace('/\s+/', ' ', $value) ?? ''), MB_CASE_TITLE, 'UTF-8');
    }

    private function accountKey(string $firstName, string $lastName): string
    {
        return mb_strtolower(trim($firstName . ' ' . $lastName), 'UTF-8');
    }
}
