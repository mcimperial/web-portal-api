<?php

namespace Modules\ClientMobileData\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;
use Modules\ClientMobileData\App\Models\Doctor;
use Modules\ClientMobileData\App\Models\Hospital;
use Modules\ClientMobileData\App\Models\Masterlist;
use Modules\ClientMobileData\App\Models\MobileComplaint;
use Modules\ClientMobileData\App\Models\MobileLoaRequestDetail;
use Modules\ClientMobileData\App\Models\MobileLoaRequestSubmission;
use Modules\ClientMobileData\App\Models\PortalComplaint;
use Modules\ClientMobileData\App\Models\PortalRequest;
use Modules\ClientMobileData\App\Services\ClientCareAuthenticationException;
use Modules\ClientMobileData\App\Services\ClientCareLoaApiService;
use Modules\ClientMobileData\App\Services\MemberAccess;
use RuntimeException;

/**
 * LOA / HMO requests from llibiapp_portal.app_portal_requests.
 */
class LoaRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'member_id' => ['sometimes', 'string', 'max:15'],
            'status' => ['sometimes', 'string', 'in:Approved,Denied,Pending Approval,Used,Sent'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('clientmobiledata.max_per_page', 100)],
        ]);

        $member = MemberAccess::resolve($request, $request->input('member_id'));

        if (! $member) {
            return response()->json(['message' => 'Link a valid member profile before viewing LOA requests.'], 404);
        }

        $requests = PortalRequest::query()
            ->select(PortalRequest::PUBLIC_COLUMNS)
            ->where('member_id', $member->member_id)
            ->when($request->filled('status'), fn ($q) => $q->where('loa_status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', config('clientmobiledata.per_page', 20)));

        $details = MobileLoaRequestDetail::query()
            ->where('member_id', $member->member_id)
            ->whereIn('portal_request_id', $requests->getCollection()->pluck('id'))
            ->get()
            ->keyBy('portal_request_id');

        $requests->getCollection()->each(function (PortalRequest $loaRequest) use ($details) {
            $loaRequest->setAttribute(
                'mobile_details',
                $details->get($loaRequest->id)?->details
            );
        });

        return response()->json($requests);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'member_id' => ['sometimes', 'string', 'max:15'],
        ]);

        $member = MemberAccess::resolve($request, $request->input('member_id'));

        if (! $member) {
            return response()->json(['message' => 'Link a valid member profile before viewing LOA requests.'], 404);
        }

        $loaRequest = PortalRequest::query()
            ->select(PortalRequest::PUBLIC_COLUMNS)
            ->where('member_id', $member->member_id)
            ->find($id);

        if (! $loaRequest) {
            return response()->json(['message' => 'LOA request not found.'], 404);
        }

        $details = MobileLoaRequestDetail::query()
            ->where('portal_request_id', $loaRequest->id)
            ->where('member_id', $member->member_id)
            ->first();
        $loaRequest->setAttribute('mobile_details', $details?->details);

        return response()->json(['request' => $loaRequest]);
    }

    public function store(Request $request, ClientCareLoaApiService $clientCareApi): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            return response()->json([
                'message' => 'A valid Idempotency-Key header is required.',
            ], 422);
        }

        $validated = $request->validate([
            'member_id' => ['sometimes', 'string', 'max:15'],
            'provider_id' => ['required', 'integer'],
            'doctor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'loa_type' => ['required', 'string', 'in:consultation,laboratory'],
            'complaint_id' => ['required_if:loa_type,consultation', 'nullable', 'integer'],
            'complaint_source' => ['sometimes', 'string', 'in:catalog,custom'],
            'mobile_details' => ['required', 'array'],
            'mobile_details.patient_member_id' => ['required', 'string', 'max:15'],
            'mobile_details.patient_details_source' => ['required', 'string', 'in:personal,insurance'],
            'mobile_details.patient_last_name' => ['required', 'string', 'max:255'],
            'mobile_details.patient_first_name' => ['required', 'string', 'max:255'],
            'mobile_details.patient_birth_date' => ['required', 'date_format:Y-m-d'],
            'mobile_details.contact_number' => ['sometimes', 'nullable', 'string', 'regex:/^09[0-9]{9}$/'],
            'mobile_details.alternate_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'mobile_details.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'doctor_request' => [
                'required_if:loa_type,laboratory',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240',
            ],
        ]);

        $configuration = MobileConfiguration::query()
            ->where('config_key', 'loa_request_backend')
            ->first();
        $backend = $configuration?->config_value ?? 'system';
        if (! in_array($backend, ['system', 'third_party'], true)) {
            Log::error('Invalid mobile LOA backend configuration.');

            return response()->json(['message' => 'The LOA request backend is misconfigured.'], 500);
        }

        if ($backend === 'third_party' && ! $clientCareApi->isConfigured($configuration)) {
            Log::error('Third-party LOA backend is selected without API configuration.');

            return response()->json([
                'message' => 'The third-party LOA backend is not configured. Your request was not submitted; contact support.',
            ], 503);
        }

        // The member must exist in the sync masterlist before a LOA
        // request can be filed against it.
        $member = MemberAccess::resolve($request, $validated['member_id'] ?? null);

        if (! $member) {
            return response()->json([
                'message' => 'Link a valid member profile before submitting a LOA request.',
            ], 422);
        }

        $provider = Hospital::query()
            ->where('status', 1)
            ->where('accredited', 1)
            ->find($validated['provider_id']);

        if (! $provider) {
            return response()->json(['message' => 'Select a currently accredited provider.'], 422);
        }

        $doctor = null;
        if (! empty($validated['doctor_id'])) {
            $doctor = Doctor::query()
                ->where('status', 1)
                ->whereHas('clinics', fn ($query) => $query
                    ->where('hospital_id', $provider->id)
                    ->where('status', 1))
                ->find($validated['doctor_id']);

            if (! $doctor) {
                return response()->json([
                    'message' => 'Select an active doctor affiliated with the selected provider.',
                ], 422);
            }
        }

        $complaintTitle = 'Laboratory request';
        if ($validated['loa_type'] === 'consultation') {
            $complaint = ($validated['complaint_source'] ?? 'catalog') === 'custom'
                ? MobileComplaint::query()->find($validated['complaint_id'])
                : PortalComplaint::query()->where('is_status', 1)->find($validated['complaint_id']);

            if (! $complaint) {
                return response()->json(['message' => 'Select an active chief complaint.'], 422);
            }

            $complaintTitle = $complaint->title;
        }

        $mobileDetails = $validated['mobile_details'] ?? [];
        $patient = $this->resolvePatient($member, $mobileDetails['patient_member_id']);
        if (! $patient) {
            return response()->json([
                'message' => 'Select the linked member or one of their dependents as the patient.',
            ], 422);
        }

        $mobileDetails['patient'] = [
            'member_id' => $patient->member_id,
            'first_name' => $mobileDetails['patient_first_name'],
            'last_name' => $mobileDetails['patient_last_name'],
            'birth_date' => $mobileDetails['patient_birth_date'],
            'relation' => $patient->relation,
            'details_source' => $mobileDetails['patient_details_source'],
        ];

        $attachmentPath = null;
        if ($request->hasFile('doctor_request')) {
            $attachmentPath = $request->file('doctor_request')->store('mobile-loa-requests', 'local');
            if ($attachmentPath === false) {
                return response()->json(['message' => 'The doctor request could not be stored.'], 500);
            }

            $mobileDetails['doctor_request_name'] =
                $request->file('doctor_request')->getClientOriginalName();
        }

        $requestHash = $this->requestHash(
            $request,
            $validated,
            $member,
            $provider,
            $doctor,
            $complaintTitle,
            $mobileDetails
        );

        try {
            $submission = MobileLoaRequestSubmission::create([
                'idempotency_key' => $idempotencyKey,
                'member_id' => $member->member_id,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'details' => $backend === 'third_party' ? [
                    'loa_type' => $validated['loa_type'],
                    'provider_id' => $provider->id,
                    'doctor_id' => $doctor?->id,
                    'complaint' => $complaintTitle,
                    'mobile_details' => $mobileDetails,
                ] : null,
            ]);
        } catch (QueryException $exception) {
            $submission = MobileLoaRequestSubmission::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $submission) {
                $this->deleteAttachment($attachmentPath);
                throw $exception;
            }

            $this->deleteAttachment($attachmentPath);

            if (! hash_equals($submission->request_hash, $requestHash)) {
                return response()->json([
                    'message' => 'This Idempotency-Key was already used for a different LOA request.',
                ], 409);
            }

            return $this->existingSubmissionResponse($submission);
        }

        unset($validated['mobile_details']);
        unset($validated['doctor_request']);
        unset($validated['complaint_id']);
        unset($validated['complaint_source']);
        unset($validated['doctor_id']);

        if ($backend === 'system') {
            try {
                $loaRequest = PortalRequest::create([
                    ...$validated,
                    'member_id' => $member->member_id,
                    'provider_id' => $provider->id,
                    'provider' => $provider->name,
                    'doctor_id' => $doctor?->id,
                    'doctor_name' => $doctor
                        ? trim(implode(' ', array_filter([
                            $doctor->first,
                            $doctor->middle,
                            $doctor->last,
                        ])))
                        : null,
                    'complaint' => $complaintTitle,
                    'loa_attachment' => $attachmentPath,
                    'loa_status' => 'Pending Approval',
                ]);
            } catch (QueryException $exception) {
                $this->deleteAttachment($attachmentPath);
                $submission->delete();

                throw $exception;
            }
        } else {
            $employee = $this->resolveEmployee($member);
            if (! $employee) {
                $this->deleteAttachment($attachmentPath);
                $submission->delete();

                return response()->json([
                    'message' => 'The employee record could not be found for the ClientCare request.',
                ], 422);
            }

            try {
                $this->submitThirdPartyRequest(
                    $clientCareApi,
                    $configuration,
                    $request,
                    $validated['loa_type'],
                    $patient,
                    $employee,
                    $provider,
                    $doctor,
                    $complaintTitle,
                    $attachmentPath
                );
            } catch (ClientCareAuthenticationException $exception) {
                $this->deleteAttachment($attachmentPath);
                $submission->delete();
                Log::warning('ClientCare rejected the credentials for a mobile LOA request.');

                return response()->json([
                    'message' => 'ClientCare rejected the API credentials. No LOA was submitted; contact support.',
                ], 502);
            } catch (ConnectionException|RequestException $exception) {
                $this->deleteAttachment($attachmentPath);
                Log::warning('ClientCare LOA request could not be confirmed.', [
                    'status' => $exception instanceof RequestException
                        ? $exception->response->status()
                        : null,
                ]);

                return response()->json([
                    'message' => 'ClientCare submission could not be confirmed. Do not resubmit; contact support.',
                ], 502);
            }

            $this->deleteAttachment($attachmentPath);
            $submission->status = 'completed';
            $submission->save();

            return response()->json([
                'message' => 'LOA request submitted successfully.',
                'request' => null,
            ], 201);
        }

        $submission->portal_request_id = $loaRequest->id;
        $submission->save();

        try {
            MobileLoaRequestDetail::create([
                'portal_request_id' => $loaRequest->id,
                'member_id' => $member->member_id,
                'details' => $mobileDetails,
            ]);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'message' => "LOA request {$loaRequest->id} was saved, but its mobile details could not be saved. Do not resubmit; contact support.",
            ], 503);
        }

        $submission->status = 'completed';
        $submission->save();

        return response()->json([
            'message' => 'LOA request submitted successfully.',
            'request' => [
                ...$loaRequest->only(PortalRequest::PUBLIC_COLUMNS),
                'mobile_details' => $mobileDetails,
            ],
        ], 201);
    }

    private function existingSubmissionResponse(MobileLoaRequestSubmission $submission): JsonResponse
    {
        if ($submission->status !== 'completed') {
            return response()->json([
                'message' => 'This LOA submission is already being processed or was saved. Do not submit it again.',
                'portal_request_id' => $submission->portal_request_id,
            ], 409);
        }

        if ($submission->portal_request_id === null) {
            return response()->json([
                'message' => 'LOA request submitted successfully.',
                'request' => null,
            ], 200);
        }

        $loaRequest = PortalRequest::query()
            ->select(PortalRequest::PUBLIC_COLUMNS)
            ->find($submission->portal_request_id);

        if (! $loaRequest) {
            return response()->json([
                'message' => 'The original LOA submission was recorded but could not be retrieved. Do not submit it again; contact support.',
            ], 503);
        }

        $details = MobileLoaRequestDetail::query()
            ->where('portal_request_id', $loaRequest->id)
            ->where('member_id', $submission->member_id)
            ->first();

        return response()->json([
            'message' => 'LOA request submitted successfully.',
            'request' => [
                ...$loaRequest->only(PortalRequest::PUBLIC_COLUMNS),
                'mobile_details' => $details?->details,
            ],
        ], 200);
    }

    private function requestHash(
        Request $request,
        array $validated,
        Masterlist $member,
        Hospital $provider,
        ?Doctor $doctor,
        string $complaintTitle,
        array $mobileDetails
    ): string {
        $data = [
            'member_id' => $member->member_id,
            'provider_id' => $provider->id,
            'doctor_id' => $doctor?->id,
            'loa_type' => $validated['loa_type'],
            'complaint_id' => $validated['complaint_id'] ?? null,
            'complaint_source' => $validated['complaint_source'] ?? null,
            'complaint_title' => $complaintTitle,
            'mobile_details' => $mobileDetails,
            'attachment_hash' => $request->hasFile('doctor_request')
                ? hash_file('sha256', $request->file('doctor_request')->getRealPath())
                : null,
        ];

        return hash('sha256', json_encode($this->sortKeysRecursively($data), JSON_THROW_ON_ERROR));
    }

    private function sortKeysRecursively(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortKeysRecursively($value);
            }
        }

        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    private function submitThirdPartyRequest(
        ClientCareLoaApiService $clientCareApi,
        MobileConfiguration $configuration,
        Request $request,
        string $loaType,
        Masterlist $patient,
        Masterlist $employee,
        Hospital $provider,
        ?Doctor $doctor,
        string $complaintTitle,
        ?string $attachmentPath
    ): Response {
        $doctorName = $doctor
            ? trim(implode(' ', array_filter([
                $doctor->first,
                $doctor->middle,
                $doctor->last,
            ])))
            : '--';
        $providerValue = $provider->id.'||'.$provider->name.'--'.($doctor?->id ?? 0).'||'.$doctorName;

        $optionalContactDetails = [];
        if ($request->filled('mobile_details.alternate_email')) {
            $optionalContactDetails['alt_email'] = $request->input('mobile_details.alternate_email');
        }
        if ($request->filled('mobile_details.contact_number')) {
            $optionalContactDetails['contact'] = $request->input('mobile_details.contact_number');
        }

        $payload = [
            'verificationDetailsType' => 'insurance',
            'erCardNumber' => $patient->member_id,
            'dob' => Carbon::parse($patient->birth_date)->toDateString(),
            'patientType' => strtoupper((string) $patient->relation) === 'EMPLOYEE'
                ? 'employee'
                : 'dependent',
            'patientFirstName' => $patient->first_name,
            'patientLastName' => $patient->last_name,
            'employeeFirstName' => $employee->first_name,
            'employeeLastName' => $employee->last_name,
            'email' => (string) $request->user()->email,
            ...$optionalContactDetails,
            'platform' => 'mobile',
            'provider' => $providerValue,
            'loaType' => $loaType,
        ];

        if ($loaType === 'consultation') {
            $payload['complaint'] = [['label' => $complaintTitle]];

            return $clientCareApi->submitConsultation($configuration, $payload);
        }

        $file = $request->file('doctor_request');
        if (! $file || $attachmentPath === null) {
            throw new RuntimeException('The laboratory request attachment is unavailable.');
        }

        $payload['cceId'] = 'null';

        return $clientCareApi->submitLaboratory(
            $configuration,
            $payload,
            Storage::disk('local')->get($attachmentPath),
            $file->getClientOriginalName()
        );
    }

    private function resolveEmployee(Masterlist $member): ?Masterlist
    {
        if (strtoupper((string) $member->relation) === 'EMPLOYEE') {
            return $member;
        }

        if (empty($member->empcode)) {
            return null;
        }

        return Masterlist::query()
            ->select(Masterlist::PUBLIC_COLUMNS)
            ->where('company_code', $member->company_code)
            ->where('empcode', $member->empcode)
            ->where('relation', 'EMPLOYEE')
            ->first();
    }

    private function deleteAttachment(?string $attachmentPath): void
    {
        if ($attachmentPath !== null && ! Storage::disk('local')->delete($attachmentPath)) {
            report(new RuntimeException('Unable to remove an orphaned mobile LOA attachment.'));
        }
    }

    private function resolvePatient(Masterlist $member, string $patientMemberId): ?Masterlist
    {
        if ($patientMemberId === $member->member_id) {
            return $member;
        }

        if (empty($member->empcode)) {
            return null;
        }

        return Masterlist::query()
            ->select(Masterlist::PUBLIC_COLUMNS)
            ->where('member_id', $patientMemberId)
            ->where('company_code', $member->company_code)
            ->where('empcode', $member->empcode)
            ->where('relation', '!=', 'EMPLOYEE')
            ->first();
    }
}
