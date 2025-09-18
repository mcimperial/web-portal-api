<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Dependent;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

use Modules\ClientMasterlist\App\Models\HealthInsurance;
use Modules\ClientMasterlist\App\Models\Attachment;
use Modules\ClientMasterlist\App\Models\Enrollee;

use App\Http\Traits\UppercaseInput;

class EnrolleeManageDependentController extends Controller
{
    use UppercaseInput;
    /**
     * Display a listing of the dependents.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Dependent::query();
        if ($request->has('principal_id')) {
            $query->where('principal_id', $request->input('principal_id'));
        }

        return response()->json([
            'data' => $query->get()
        ]);
    }

    /**
     * Batch store dependents for an enrollee.
     */
    public function storeBatch($enrolleeId, Request $request): JsonResponse
    {
        $dependents = $request->input('dependents', []);
        // Get the employee_id of the principal enrollee
        $principal = Enrollee::find($enrolleeId);
        $employeeId = $principal ? $principal->employee_id : null;
        $results = [];
        $errors = [];

        // Get all current dependents for this enrollee
        $currentDependents = Dependent::where('principal_id', $enrolleeId)->get();
        $submittedIds = collect($dependents)->pluck('id')->filter()->all();

        // Soft-delete dependents not in the submitted list
        foreach ($currentDependents as $curDep) {
            if (!in_array($curDep->id, $submittedIds)) {
                $curDep->delete(); // assumes SoftDeletes trait is used
            }
        }

        foreach ($dependents as $i => $dep) {
            try {
                $validated = validator($dep, [
                    'employee_id' => 'sometimes|string|nullable',
                    'first_name' => 'required|string',
                    'last_name' => 'required|string',
                    'relation' => 'required|string',
                    'birth_date' => 'required|date',
                    'gender' => 'required|string',
                    'marital_status' => 'required|string',
                ])->validate();

                $validated['principal_id'] = $enrolleeId;
                if ($employeeId) {
                    $validated['employee_id'] = $employeeId;
                }
                $validated['enrollment_status'] = 'PENDING';
                $validated = $this->uppercaseStrings($validated);

                // Save/update dependent
                $dependentModel = null;
                if (!empty($dep['id'])) {
                    $dependentModel = Dependent::where('id', $dep['id'])
                        ->where('principal_id', $enrolleeId)
                        ->first();
                    if ($dependentModel) {
                        $dependentModel->update($validated);
                        $results[] = $dependentModel;
                    } else {
                        unset($validated['id']);
                        $dependentModel = Dependent::create($validated);
                        $results[] = $dependentModel;
                    }
                } else {
                    $dependentModel = Dependent::create($validated);
                    $results[] = $dependentModel;
                }

                // Handle skip hierarchy fields in HealthInsurance
                $health = HealthInsurance::where('dependent_id', $dependentModel->id)->first();
                if (isset($dep['is_skipping']) && $dep['is_skipping']) {
                    $skipFields = [
                        'is_skipping' => $dep['is_skipping'],
                        'reason_for_skipping' => isset($dep['reason_for_skipping']) ? $dep['reason_for_skipping'] : null,
                        'attachment_for_skipping' => isset($dep['attachment_for_skipping']) ? $dep['attachment_for_skipping'] : null,
                    ];
                    if ($health) {
                        $health->update($skipFields);
                    } else {
                        $skipFields['dependent_id'] = $dependentModel->id;
                        $skipFields['principal_id'] = $enrolleeId;
                        HealthInsurance::create($skipFields);
                    }
                } else {
                    // If is_skipping is not set, remove skip hierarchy fields and delete skip_hierarchy attachments
                    if ($health) {
                        $health->update([
                            'is_skipping' => 0,
                            'reason_for_skipping' => null,
                            'attachment_for_skipping' => null,
                        ]);
                    }
                    // Delete skip_hierarchy attachments for this dependent
                    $skipAttachments = Attachment::where('dependent_id', $dependentModel->id)
                        ->where('attachment_for', 'skip_hierarchy')->get();
                    $attachmentController = new AttachmentController();
                    foreach ($skipAttachments as $attachment) {
                        $attachmentController->destroy($attachment->id);
                    }
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $i,
                    'error' => $e->getMessage(),
                    'data' => $dep
                ];
            }
        }

        // If all dependents processed successfully, update principal's enrollment_status to SUBMITTED
        $principal->enrollment_status = 'SUBMITTED';
        $principal->save();


        return response()->json([
            'message' => 'Batch dependents processed',
            'created' => $results,
            'errors' => $errors
        ], count($errors) ? 207 : 201);
    }
}
