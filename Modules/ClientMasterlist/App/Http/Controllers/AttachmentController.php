<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Modules\ClientMasterlist\App\Models\Enrollee;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Attachment::query();
        if ($request->has('dependent_id')) {
            $query->where('dependent_id', $request->input('dependent_id'));
        }
        if ($request->has('principal_id')) {
            $query->where('principal_id', $request->input('principal_id'));
        }
        if ($request->has('notification_id')) {
            $query->where('notification_id', $request->input('notification_id'));
        }
        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        #set_time_limit(300);
        $validated = $request->validate([
            'principal_id' => 'nullable|integer|exists:cm_principal,id',
            'dependent_id' => 'nullable|integer|exists:cm_dependent,id',
            'notification_id' => 'nullable|integer|exists:cm_notification,id',
            'employee_id' => 'nullable|string',
            'company_code' => 'nullable|string',
            'provider' => 'nullable|string',
            'attachment_for' => 'required|in:skip_hierarchy,birth_certificate,required_document,other',
            'file' => 'required|file',
        ]);

        // Enrollee/dependent attachment: use employee_id as main folder
        $employeeId = $validated['employee_id'] ?? '';

        $dependentBirthdate = null;
        $dependent = null;
        if (!empty($validated['dependent_id'])) {
            $dependent = \Modules\ClientMasterlist\App\Models\Dependent::find($validated['dependent_id']);
            if ($dependent && !empty($dependent->birth_date)) {
                $dependentBirthdate = date('Y-m-d', strtotime($dependent->birth_date));
            }
        } else if (!empty($validated['principal_id'])) {
            // If no dependent_id, get the last dependent of the principal
            $lastDependent = \Modules\ClientMasterlist\App\Models\Dependent::where('principal_id', $validated['principal_id'])
                ->orderByDesc('id')->first();
            if ($lastDependent && !empty($lastDependent->birth_date)) {
                $dependentBirthdate = date('Y-m-d', strtotime($lastDependent->birth_date));
                $dependent = $lastDependent;
            }
        }

        // If principal_id is provided but company_code or provider is missing, fetch them
        $enrollee = Enrollee::with(['enrollment', 'insuranceProvider'])->find($dependent->principal_id ?? null);
        if ($enrollee) {
            // Get company_code from companies table using company_id from enrollment
            $companyCode = $validated['company_code'] ?? '';
            if (empty($companyCode) && $enrollee->enrollment && $enrollee->enrollment->company_id) {
                $company = DB::table('company')->where('id', $enrollee->enrollment->company_id)->first();
                if ($company && isset($company->company_code)) {
                    $companyCode = $company->company_code;
                }
            }
            // Get provider from insuranceProvider relation
            $provider = $validated['provider'] ?? '';
            if (empty($provider) && $enrollee->insuranceProvider) {
                $provider = $enrollee->insuranceProvider->title ?? '';
            }
            // Overwrite validated values if found
            $validated['company_code'] = $companyCode;
            $validated['provider'] = $provider;
        }

        $file = $request->file('file');
        $companyCode = $validated['company_code'] ?? '';
        $provider = $validated['provider'] ?? '';
        $attachmentFor = $validated['attachment_for'];
        $fileName = time() . '_' . $file->getClientOriginalName();

        // Flexible path logic
        if (!empty($validated['notification_id'])) {
            // Notification attachment: use notification_id in path
            $notificationId = $validated['notification_id'];
            $path = 'notification/' . $notificationId . '/';
        } else {

            if ($dependentBirthdate) {
                $path = $companyCode . '/' . $provider . '/' . $employeeId . '/' . $dependentBirthdate . '/' . $attachmentFor . '/';
            } else {
                $path = $companyCode . '/' . $provider . '/' . $employeeId . '/' . $attachmentFor . '/';
            }
        }

        $fullPath = $path . $fileName;
        $log = Storage::disk('spaces')->put($fullPath, file_get_contents($file), ['visibility' => 'public']);

        // Manually build the public URL for Spaces
        $endpoint = rtrim(config('filesystems.disks.spaces.endpoint'), '/');
        if (str_starts_with($endpoint, 'https://')) {
            $publicUrl = 'https://llibi-self-enrollment.' . substr($endpoint, 8) . '/' . ltrim($fullPath, '/');
        } else {
            $publicUrl = $endpoint . '/' . ltrim($fullPath, '/');
        }

        // Always create a new attachment record (do not update old ones)
        $attachment = Attachment::create([
            'principal_id' => $validated['principal_id'] ?? null,
            'dependent_id' => $validated['dependent_id'] ?? null,
            'notification_id' => $validated['notification_id'] ?? null,
            'attachment_for' => $attachmentFor,
            'file_path' => $publicUrl,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientMimeType(),
        ]);

        return response()->json([
            'success' => $log,
            'url' => $publicUrl,
            'attachment' => $attachment
        ], 201);
    }

    public function destroy($id)
    {
        $attachment = Attachment::findOrFail($id);
        // Extract the object key from the file_path
        $endpoint = rtrim(config('filesystems.disks.spaces.endpoint'), '/');
        $publicUrlPrefix = 'https://llibi-self-enrollment.' . substr($endpoint, 8) . '/';
        $objectKey = ltrim(str_replace($publicUrlPrefix, '', $attachment->file_path), '/');
        // Delete the file from Spaces
        Storage::disk('spaces')->delete($objectKey);
        $attachment->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Delete all attachments for a given dependent_id.
     */
    public function destroyByDependentId($dependentId)
    {
        $attachments = Attachment::where('dependent_id', $dependentId)->get();
        $deleted = 0;
        foreach ($attachments as $attachment) {
            // Extract the object key from the file_path
            $endpoint = rtrim(config('filesystems.disks.spaces.endpoint'), '/');
            $publicUrlPrefix = 'https://llibi-self-enrollment.' . substr($endpoint, 8) . '/';
            $objectKey = ltrim(str_replace($publicUrlPrefix, '', $attachment->file_path), '/');
            // Delete the file from Spaces
            Storage::disk('spaces')->delete($objectKey);
            $attachment->delete();
            $deleted++;
        }
        return response()->json(['message' => "Deleted $deleted attachments for dependent_id $dependentId"]);
    }
}
