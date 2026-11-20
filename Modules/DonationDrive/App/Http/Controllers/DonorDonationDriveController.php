<?php

namespace Modules\DonationDrive\App\Http\Controllers;

use App\Http\Helpers\SmsHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Modules\ClientMasterlist\App\Services\EmailSender;
use Modules\DonationDrive\App\Models\Donation;
use Modules\DonationDrive\App\Models\DonationDonor;
use Modules\DonationDrive\App\Models\DonationDrive;
use Modules\DonationDrive\App\Models\DonationDriveDocument;
use Modules\DonationDrive\App\Support\DonationOtpMessage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DonorDonationDriveController extends Controller
{
    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required_without:phone', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:identifier', 'nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ]);

        [$channel, $identifier] = $this->normalizeIdentifier(
            $validated['identifier'] ?? $validated['phone']
        );
        $donor = DonationDonor::where($channel, $identifier)->first();
        $profile = null;

        if (! $donor) {
            if (blank($validated['first_name'] ?? null) || blank($validated['last_name'] ?? null)) {
                throw ValidationException::withMessages([
                    'profile' => "Enter your first and last name to register this {$channel}.",
                ]);
            }

            $firstName = $this->normalize($validated['first_name']);
            $lastName = $this->normalize($validated['last_name']);
            $normalizedName = $this->accountKey($firstName, $lastName);
            $nameDonor = DonationDonor::where('normalized_name', $normalizedName)->first();

            if ($nameDonor && $nameDonor->{$channel} && $nameDonor->{$channel} !== $identifier) {
                throw ValidationException::withMessages([
                    'identifier' => "This donor name is already linked to another {$channel}.",
                ]);
            }

            $donor = $nameDonor;
            $profile = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'normalized_name' => $normalizedName,
            ];
        }

        $cacheKey = $this->otpCacheKey($channel, $identifier);
        $existing = Cache::get($cacheKey);
        if ($existing && now()->timestamp - $existing['sent_at'] < 30) {
            return response()->json(['message' => 'An OTP was already sent. Please wait before requesting another.'], 429);
        }

        $otp = (string) random_int(100000, 999999);
        Cache::put($cacheKey, [
            'otp_hash' => Hash::make($otp),
            'donor_id' => $donor?->id,
            'profile' => $profile,
            'channel' => $channel,
            'attempts' => 0,
            'sent_at' => now()->timestamp,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], now()->addMinutes(5));

        $delivery = $channel === 'email'
            ? ['success' => $this->sendOtpEmail($identifier, $donor?->first_name, $otp)]
            : SmsHelper::send($identifier, DonationOtpMessage::build($otp));
        $sent = $delivery['success'] ?? false;

        if (! $sent) {
            Cache::forget($cacheKey);

            return response()->json([
                'message' => "Unable to send the OTP by {$channel}. Please check the {$channel} and try again.",
                'delivery_error' => $delivery['error'] ?? 'The message provider rejected the request.',
            ], 502);
        }

        return response()->json([
            'message' => "OTP sent to your {$channel}.",
            'channel' => $channel,
            'masked_identifier' => $channel === 'email'
                ? $this->maskEmail($identifier)
                : $this->maskPhone($identifier),
            'expires_in' => 300,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required_without:phone', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:identifier', 'nullable', 'string', 'max:30'],
            'otp' => ['required', 'digits:6'],
        ]);

        [$channel, $identifier] = $this->normalizeIdentifier(
            $validated['identifier'] ?? $validated['phone']
        );
        $cacheKey = $this->otpCacheKey($channel, $identifier);
        $pending = Cache::get($cacheKey);

        if (! $pending) {
            throw ValidationException::withMessages(['otp' => 'This OTP is invalid or has expired.']);
        }

        if (! Hash::check($validated['otp'], $pending['otp_hash'])) {
            $pending['attempts']++;
            if ($pending['attempts'] >= 5) {
                Cache::forget($cacheKey);
                throw ValidationException::withMessages(['otp' => 'Too many incorrect attempts. Request a new OTP.']);
            }

            Cache::put(
                $cacheKey,
                $pending,
                now()->addSeconds(max(1, $pending['expires_at'] - now()->timestamp))
            );
            throw ValidationException::withMessages(['otp' => 'Incorrect OTP. Please try again.']);
        }

        if ($pending['channel'] !== $channel) {
            throw ValidationException::withMessages(['otp' => 'This verification request no longer matches the selected method.']);
        }

        $donor = DB::transaction(function () use ($pending, $channel, $identifier) {
            $donor = $pending['donor_id']
                ? DonationDonor::lockForUpdate()->findOrFail($pending['donor_id'])
                : new DonationDonor($pending['profile']);
            $phoneOwner = DonationDonor::query()
                ->where($channel, $identifier)
                ->lockForUpdate()
                ->first();

            abort_if(
                $phoneOwner && $phoneOwner->id !== $donor->id,
                409,
                "This {$channel} is already linked to another donor account."
            );

            $donor->{$channel} = $identifier;
            $donor->{$channel.'_verified_at'} = now();
            $donor->save();

            return $donor;
        });

        Cache::forget($cacheKey);
        $donor->tokens()->where('name', 'donation-drive')->delete();

        return response()->json([
            'message' => ucfirst($channel).' verified.',
            'donor' => $donor->fresh(),
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
        ]);
        $previousAmount = $donation->exists ? (string) $donation->amount : null;
        $donation->amount = $validated['amount'];
        $donation->cutoff = $validated['cutoff'];
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
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Donor', 'Cut-off', 'Amount', 'Last Updated'], null, 'A1');

        foreach ($report['donations'] as $index => $donation) {
            $sheet->fromArray([
                trim($donation->donor->first_name.' '.$donation->donor->last_name),
                $donation->cutoff,
                (float) $donation->amount,
                $donation->updated_at->format('Y-m-d H:i:s'),
            ], null, 'A'.($index + 2));
        }

        $path = storage_path('app/donation-admin-report-'.$drive->id.'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $drive->title).'-donations.xlsx';

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
        return mb_strtolower(trim($firstName.' '.$lastName), 'UTF-8');
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '0'.substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        if (strlen($digits) !== 11 || ! str_starts_with($digits, '09')) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a valid Philippine mobile number (09XXXXXXXXX).',
            ]);
        }

        return $digits;
    }

    private function normalizeIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return ['email', mb_strtolower($identifier, 'UTF-8')];
        }

        return ['phone', $this->normalizePhone($identifier)];
    }

    private function otpCacheKey(string $channel, string $identifier): string
    {
        return 'donation-drive:otp:'.$channel.':'.hash('sha256', $identifier);
    }

    private function maskPhone(string $phone): string
    {
        return substr($phone, 0, 4).str_repeat('*', 5).substr($phone, -2);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    private function sendOtpEmail(string $email, ?string $name, string $otp): bool
    {
        $body = View::make('donationdrive::emails.donation-otp', compact('name', 'otp'))->render();
        $sender = new EmailSender(
            $email,
            $body,
            'Giving Together - Your Donation Portal Code'
        );

        return (bool) $sender->sendLlibi();
    }
}
