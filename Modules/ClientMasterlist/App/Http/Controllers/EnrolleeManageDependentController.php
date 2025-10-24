<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use Modules\ClientMasterlist\App\Models\Attachment;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\Notification;

use Modules\ClientMasterlist\App\Http\Controllers\SendNotificationController;

use App\Http\Traits\UppercaseInput;

class EnrolleeManageDependentController extends Controller
{
    use UppercaseInput;

    public function show($uuid)
    {
        $enrollee = Enrollee::with(['dependents.healthInsurance', 'healthInsurance',  'enrollment', 'enrollment.company'])->where('uuid', $uuid)->first();

        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }

        // Map dependents to include skip hierarchy fields from health_insurance
        $dependentsWithSkipHierarchy = collect($enrollee->dependents)->map(function ($dep) {
            $health = $dep->healthInsurance;
            return [
                'id' => $dep->id,
                'first_name' => $dep->first_name,
                'last_name' => $dep->last_name,
                'middle_name' => $dep->middle_name,
                'relation' => $dep->relation,
                'birth_date' => $dep->birth_date,
                'gender' => $dep->gender,
                'marital_status' => $dep->marital_status,
                'enrollment_status' => $dep->enrollment_status,
                // ... add other dependent fields as needed ...
                'health_insurance' => $health,
                // Populate skip hierarchy fields from health_insurance if present
                'is_skipping' => $health ? $health->is_skipping : null,
                'reason_for_skipping' => $health ? $health->reason_for_skipping : null,
                'attachment_for_skipping' => $health ? $health->attachment_for_skipping : null,
            ];
        });

        // Return enrollee with dependents including skip hierarchy fields
        $enrolleeArr = $enrollee->toArray();
        $enrolleeArr['dependents'] = $dependentsWithSkipHierarchy;

        return response()->json($enrolleeArr);
    }

    public function updateGenderAndMaritalStatus(Request $request, $uuid)
    {
        $enrollee = Enrollee::where('uuid', $uuid)->first();
        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }

        $validated = $request->validate([
            'gender' => 'required|string|max:255',
            'marital_status' => 'required|string|max:255'
        ]);

        $enrollee->fill($validated);
        $enrollee->save();

        return response()->json([
            'success' => true,
            'message' => 'Enrollee updated successfully',
            'enrollee' => $enrollee
        ]);
    }

    public function updateOnRenewal(Request $request, $uuid)
    {
        $enrollee = Enrollee::where('uuid', $uuid)->first();
        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }

        $validated = $request->validate([
            'enrollment_status' => 'required|string|max:255'
        ]);

        // Update overage dependents' enrollment_status to OVERAGE
        $dependents = Dependent::where('principal_id', $enrollee->id)->get();

        foreach ($dependents as $dep) {
            $birthDate = $dep->birth_date;
            $relation = strtoupper($dep->relation);
            $isOverage = false;
            if ($birthDate && $relation) {
                $age = \Carbon\Carbon::parse($birthDate)->age;
                if (($relation === 'CHILD' || $relation === 'SIBLING') && $age > 23) {
                    $isOverage = true;
                } else if (($relation === 'SPOUSE' || $relation === 'PARENT' || $relation === 'DOMESTIC PARTNER') && $age > 65) {
                    $isOverage = true;
                }
                // Add more rules for other relations if needed
            }
            if ($isOverage) {
                $dep->enrollment_status = 'OVERAGE';
                $dep->save();
            }
        }

        if ($validated['enrollment_status'] === 'SUBMITTED') {
            $this->sendEmailNotification($enrollee->enrollment_id, $enrollee->id, $enrollee->email1);
        }

        $enrollee->fill($validated);
        $enrollee->save();

        return response()->json([
            'success' => true,
            'message' => 'Enrollee renewal updated successfully',
            'enrollee' => $enrollee
        ]);
    }

    public function update(Request $request, $uuid)
    {
        $enrollee = Enrollee::where('uuid', $uuid)->first();

        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'birth_date' => 'required|date',
            'gender' => 'required|string|max:255',
            'marital_status' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'enrollment_status' => 'required|string'
        ]);

        $validated = $this->uppercaseStrings($validated);

        $enrollee->fill($validated);
        $enrollee->save();

        if ($enrollee->enrollment_status === 'SUBMITTED') {
            $this->sendEmailNotification($enrollee->enrollment_id, $enrollee->id, $enrollee->email1);
        }

        return response()->json([
            'success' => true,
            'message' => 'Enrollee updated successfully',
            'enrollee' => $enrollee
        ]);
    }

    /**
     * Batch store dependents for an enrollee.
     */
    public function storeBatch($enrolleeId, Request $request)
    {
        $results = [];
        $errors = [];

        $dependents = $request->input('dependents', []);
        // Get the employee_id of the principal enrollee
        $principal = Enrollee::with('healthInsurance')->find($enrolleeId);
        $employeeId = $principal ? $principal->employee_id : false;
        $isRenewal = $principal && $principal->healthInsurance ? $principal->healthInsurance->is_renewal : null;

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

                isset($dep['is_skipping']) && $dep['is_skipping'] ? $validated['enrollment_status'] = 'SKIPPED' : null;
                // Uppercase string fields
                $validated = $this->uppercaseStrings($validated);

                // Save/update dependent
                $dependentModel = null;
                if (!empty($dep['id'])) {

                    $dependentModel = Dependent::with('healthInsurance')
                        ->where('id', $dep['id'])
                        ->where('principal_id', $enrolleeId)
                        ->first();

                    if ($dependentModel) {

                        if ($isRenewal && !empty($dependentModel->healthInsurance->certificate_number)) {
                            $validated['enrollment_status'] = 'SUBMITTED';
                        } else if (!$isRenewal) {
                            $validated['enrollment_status'] = 'FOR-ENROLLMENT';
                        }

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

                $healthInsuranceUpdate = [
                    'is_skipping' => $dep['is_skipping'] ?? 0,
                    'reason_for_skipping' => $dep['reason_for_skipping'] ?? null,
                    'attachment_for_skipping' => $dep['attachment_for_skipping'] ?? null,
                    'is_renewal' => $isRenewal,
                ];

                if ($health) {
                    $health->update($healthInsuranceUpdate);
                } else {
                    $healthInsuranceUpdate['dependent_id'] = $dependentModel->id;
                    HealthInsurance::create($healthInsuranceUpdate);
                }

                if (!isset($dep['is_skipping']) || (isset($dep['is_skipping']) && !$dep['is_skipping'])) {

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

        $this->sendEmailNotification($principal->enrollment_id, $enrolleeId, $principal->email1);

        return response()->json([
            'message' => 'Batch dependents processed',
            'created' => $results,
            'errors' => $errors
        ], count($errors) ? 207 : 201);
    }

    private function sendEmailNotification($enrollment_id, $enrollee_id, $email)
    {
        $notification = Notification::where('enrollment_id', $enrollment_id)
            ->where('notification_type', 'RESPONSE: SUBMISSION OF ENROLLMENT (SUBMITTED)')
            ->whereNull('deleted_at')
            ->orderBy('updated_at', 'desc')
            ->first();

        if ($notification) {
            $notificationController = new SendNotificationController();
            $notificationRequest = new Request([
                'notification_id' => $notification->id,
                'to' => $email,
                'cc' => $notification->cc,
                'bcc' => $notification->bcc,
                'enrollee_id' => $enrollee_id,
                'send_as_multiple' => false,
            ]);
            $notificationController->send($notificationRequest);
        }
    }
}
