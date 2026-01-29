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
use App\Http\Traits\LogsActions;

class EnrolleeManageDependentController extends Controller
{
    use UppercaseInput, LogsActions;

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
                'suffix' => $dep->suffix,
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
        $enrollee = Enrollee::with('enrollment.company')->where('uuid', $uuid)->first();
        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }
        
        $oldValues = $enrollee->toArray();
        $oldMaritalStatus = $enrollee->marital_status;

        $validated = $request->validate([
            'gender' => 'required|string|max:255',
            'marital_status' => 'required|string|max:255'
        ]);

        $enrollee->fill($validated);
        $enrollee->save();

        $deletedDependentsCount = 0;

        // Check if marital status changed and delete dependents only for specific company codes
        if ($oldMaritalStatus !== $validated['marital_status']) {
            $companyCode = $enrollee->enrollment && $enrollee->enrollment->company 
                ? $enrollee->enrollment->company->company_code 
                : null;

            // Array of company codes that require dependent deletion on marital status change
            $companyCodesWithDependentDeletion = [
                'REMOTE',
                // Add more company codes here as needed
            ];

            if ($companyCode && in_array($companyCode, $companyCodesWithDependentDeletion)) {
                // Get all dependents for this enrollee
                $dependents = Dependent::where('principal_id', $enrollee->id)->get();
                
                foreach ($dependents as $dependent) {
                    // Delete associated health insurance record
                    HealthInsurance::where('dependent_id', $dependent->id)->delete();
                    
                    // Delete associated attachments
                    Attachment::where('dependent_id', $dependent->id)->delete();
                    
                    // Soft delete the dependent
                    $dependent->delete();
                    $deletedDependentsCount++;
                }
            }
        }
        
        // Log the update action
        $this->logUpdate($enrollee, $oldValues, [
            'action' => 'update_gender_marital_status',
            'uuid' => $uuid,
            'marital_status_changed' => $oldMaritalStatus !== $validated['marital_status'],
            'dependents_deleted' => $deletedDependentsCount
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Enrollee updated successfully',
            'enrollee' => $enrollee,
            'dependents_deleted' => $deletedDependentsCount
        ]);
    }

    public function updateOnRenewal(Request $request, $uuid)
    {
        $enrollee = Enrollee::with('enrollment')->where('uuid', $uuid)->first();
        if (!$enrollee) {
            return response()->json(['message' => 'Enrollee not found'], 404);
        }
        
        $oldValues = $enrollee->toArray();

        $validated = $request->validate([
            'enrollment_status' => 'required|string|max:255'
        ]);

        // Get age_restriction from enrollment settings
        $ageRestriction = $enrollee->enrollment ? $enrollee->enrollment->age_restriction : null;

        // Parse age restriction format: CH:15D-25,AD:25-75
        $parsedRestriction = $this->parseAgeRestriction($ageRestriction);

        // Update overage dependents' enrollment_status to OVERAGE
        $dependents = Dependent::where('principal_id', $enrollee->id)->get();

        foreach ($dependents as $dep) {
            $birthDate = $dep->birth_date;
            $relation = strtoupper($dep->relation);
            $isOverage = false;
            
            if ($birthDate && $relation) {
                $age = \Carbon\Carbon::parse($birthDate)->age;
                
                // Get age limits based on relation type
                $maxAge = $this->getMaxAgeForRelation($relation, $parsedRestriction);
                
                if ($maxAge !== null && $age > $maxAge) {
                    $isOverage = true;
                }
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
        
        // Log the update action
        $this->logUpdate($enrollee, $oldValues, [
            'action' => 'update_on_renewal',
            'uuid' => $uuid,
            'overage_dependents_updated' => true,
            'age_restriction_used' => $ageRestriction
        ]);

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
        
        $oldValues = $enrollee->toArray();

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'suffix' => 'nullable|string|max:255',
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
        
        // Log the update action
        $this->logUpdate($enrollee, $oldValues, [
            'action' => 'enrollee_update',
            'uuid' => $uuid
        ]);

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
                    'middle_name' => 'nullable|string',
                    'suffix' => 'nullable|string',
                    'relation' => 'required|string',
                    'birth_date' => 'required|date',
                    'gender' => 'required|string',
                    'marital_status' => 'required|string',
                ])->validate();

                $validated['principal_id'] = $enrolleeId;

                if ($employeeId) {
                    $validated['employee_id'] = $employeeId;
                }

                $validated['enrollment_status'] = 'FOR-APPROVAL';

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
                            $validated['enrollment_status'] = 'FOR-APPROVAL';
                        } else if (!$isRenewal) {
                            $validated['enrollment_status'] = 'FOR-APPROVAL';
                        }

                        $dependentModel->update($validated);
                        
                        // Log the update action
                        $this->logUpdate($dependentModel, $dependentModel->getOriginal(), [
                            'action' => 'batch_update_dependent',
                            'principal_id' => $enrolleeId
                        ]);
                        
                        $results[] = $dependentModel;
                    } else {
                        unset($validated['id']);
                        $dependentModel = Dependent::create($validated);
                        
                        // Log the create action
                        $this->logCreate($dependentModel, [
                            'action' => 'batch_create_dependent',
                            'principal_id' => $enrolleeId
                        ]);
                        
                        $results[] = $dependentModel;
                    }
                } else {
                    $dependentModel = Dependent::create($validated);
                    
                    // Log the create action
                    $this->logCreate($dependentModel, [
                        'action' => 'batch_create_dependent',
                        'principal_id' => $enrolleeId
                    ]);
                    
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
        $oldStatus = $principal->enrollment_status;
        $principal->enrollment_status = 'SUBMITTED';
        $principal->save();

        // Log the status change to SUBMITTED
        $this->logAction(
            'UPDATE',
            $principal,
            ['enrollment_status' => $oldStatus],
            ['enrollment_status' => 'SUBMITTED'],
            "Enrollment submitted by {$principal->first_name} {$principal->last_name}",
            [
                'enrollment_id' => $principal->enrollment_id,
                'action' => 'enrollment_submitted',
                'dependents_count' => count($dependents)
            ]
        );

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

    /**
     * Parse age restriction string format
     * Format: CH:15D-25,AD:25-75
     * - CH = Child/Sibling
     * - AD = Adult (Parent/Spouse/Domestic Partner)
     * - D suffix = Days (e.g., 15D)
     * - No suffix = Years
     */
    private function parseAgeRestriction($ageRestriction)
    {
        if (empty($ageRestriction)) {
            return null;
        }

        $result = [];
        $parts = array_map('trim', explode(',', $ageRestriction));

        foreach ($parts as $part) {
            $segments = array_map('trim', explode(':', $part));
            if (count($segments) !== 2) continue;

            list($prefix, $range) = $segments;
            $rangeParts = array_map('trim', explode('-', $range));
            if (count($rangeParts) !== 2) continue;

            list($minStr, $maxStr) = $rangeParts;

            // Check if min has 'D' suffix (days)
            $minInDays = false;
            if (strtoupper(substr($minStr, -1)) === 'D') {
                $min = intval(substr($minStr, 0, -1));
                $minInDays = true;
            } else {
                $min = intval($minStr);
            }

            $max = intval($maxStr);

            $prefix = strtoupper($prefix);
            if ($prefix === 'CH') {
                $result['child'] = ['min' => $min, 'max' => $max, 'minInDays' => $minInDays];
            } else if ($prefix === 'AD') {
                $result['adult'] = ['min' => $min, 'max' => $max, 'minInDays' => $minInDays];
            }
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Get maximum age for a relation type
     */
    private function getMaxAgeForRelation($relation, $parsedRestriction)
    {
        if ($parsedRestriction === null) {
            // Default values
            if (in_array($relation, ['CHILD', 'SIBLING'])) {
                return 23;
            } else if (in_array($relation, ['PARENT', 'SPOUSE', 'DOMESTIC PARTNER'])) {
                return 65;
            }
            return null;
        }

        // Use parsed restriction
        if (in_array($relation, ['CHILD', 'SIBLING']) && isset($parsedRestriction['child'])) {
            return $parsedRestriction['child']['max'];
        } else if (in_array($relation, ['PARENT', 'SPOUSE', 'DOMESTIC PARTNER']) && isset($parsedRestriction['adult'])) {
            return $parsedRestriction['adult']['max'];
        }

        // Fallback to defaults
        if (in_array($relation, ['CHILD', 'SIBLING'])) {
            return 23;
        } else if (in_array($relation, ['PARENT', 'SPOUSE', 'DOMESTIC PARTNER'])) {
            return 65;
        }

        return null;
    }
}
