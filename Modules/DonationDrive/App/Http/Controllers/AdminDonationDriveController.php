<?php

namespace Modules\DonationDrive\App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\DonationDrive\App\Models\DonationDonor;
use Modules\DonationDrive\App\Models\DonationDrive;
use Modules\DonationDrive\App\Models\DonationDriveDocument;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminDonationDriveController extends Controller
{
    public function index(): JsonResponse
    {
        $drives = DonationDrive::query()
            ->with([
                'documents',
                'reportUsers:id,name,email',
                'histories.user:id,name',
            ])
            ->withCount('documents')
            ->withCount('donations')
            ->withSum('donations as total_amount', 'amount')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $drives]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $drive = DonationDrive::create([
            ...$validated,
            'created_by' => $request->user()->id,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);
        $this->record($drive, $request, 'created');

        return response()->json(['message' => 'Donation drive created.', 'data' => $drive], 201);
    }

    public function status(Request $request, DonationDrive $drive): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $previous = $drive->is_active;
        $drive->update(['is_active' => $validated['is_active']]);
        $this->record($drive, $request, 'status_changed', $previous, [
            'status' => $drive->is_active ? 'active' : 'inactive',
        ]);

        return response()->json(['message' => 'Donation drive status updated.', 'data' => $drive]);
    }

    public function uploadDocument(Request $request, DonationDrive $drive): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx', 'max:10240'],
        ]);
        $file = $validated['document'];
        $path = $file->store("donation-drives/{$drive->id}", 'local');

        $document = $drive->documents()->create([
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);
        $this->record($drive, $request, 'document_uploaded', null, [
            'document' => $document->original_name,
        ]);

        return response()->json(['message' => 'Document uploaded.', 'data' => $document], 201);
    }

    public function deleteDocument(Request $request, DonationDriveDocument $document): JsonResponse
    {
        $drive = $document->drive;
        Storage::disk('local')->delete($document->path);
        $document->delete();
        $this->record($drive, $request, 'document_deleted', null, [
            'document' => $document->original_name,
        ]);

        return response()->json(['message' => 'Document deleted.']);
    }

    public function users(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        return response()->json([
            'data' => User::query()
                ->select(['id', 'name', 'email'])
                ->where('is_active', true)
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($nested) use ($search) {
                        $nested->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->limit(100)
                ->get(),
        ]);
    }

    public function updateReportUsers(Request $request, DonationDrive $drive): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $drive->reportUsers()->sync($validated['user_ids']);
        $this->record($drive, $request, 'report_access_updated', null, [
            'user_ids' => $validated['user_ids'],
        ]);

        return response()->json(['message' => 'Report access updated.']);
    }

    public function donors(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        return response()->json([
            'data' => DonationDonor::query()
                ->when($search !== '', function ($query) use ($search) {
                    $query->where('normalized_name', 'like', '%' . mb_strtolower($search) . '%');
                })
                ->withCount('donations')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
        ]);
    }

    public function setDonorAdmin(Request $request, DonationDonor $donor): JsonResponse
    {
        $validated = $request->validate(['is_admin' => ['required', 'boolean']]);
        $donor->update(['is_admin' => $validated['is_admin']]);

        return response()->json([
            'message' => $donor->is_admin ? 'Donor promoted to administrator.' : 'Donor administrator access removed.',
            'data' => $donor,
        ]);
    }

    public function reportDrives(Request $request): JsonResponse
    {
        $query = DonationDrive::query()
            ->withCount('donations')
            ->withSum('donations as total_amount', 'amount')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (!$this->canManageAll($request->user())) {
            $query->whereHas('reportUsers', fn ($users) => $users->whereKey($request->user()->id));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function report(Request $request, DonationDrive $drive): JsonResponse
    {
        $this->authorizeReport($request, $drive);

        return response()->json($this->reportPayload($drive));
    }

    public function export(Request $request, DonationDrive $drive): BinaryFileResponse
    {
        $this->authorizeReport($request, $drive);
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
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        foreach (range('A', 'D') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = storage_path('app/donation-drive-report-' . $drive->id . '.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $drive->title) . '-donations.xlsx';

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    private function reportPayload(DonationDrive $drive): array
    {
        $donations = $drive->donations()
            ->with(['donor:id,first_name,last_name', 'histories'])
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

    private function authorizeReport(Request $request, DonationDrive $drive): void
    {
        $user = $request->user();
        abort_unless(
            $this->canManageAll($user) || $drive->reportUsers()->whereKey($user->id)->exists(),
            403,
            'You are not assigned to this donation report.'
        );
    }

    private function canManageAll(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasModulePermission('donation_drives', 'view');
    }

    private function record(
        DonationDrive $drive,
        Request $request,
        string $action,
        ?bool $previous = null,
        array $details = []
    ): void {
        $drive->histories()->create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'previous_is_active' => $previous,
            'current_is_active' => $drive->is_active,
            'details' => $details ?: null,
        ]);
    }
}