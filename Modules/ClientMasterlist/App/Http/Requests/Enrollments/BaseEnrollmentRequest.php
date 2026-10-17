<?php

namespace Modules\ClientMasterlist\App\Http\Requests\Enrollments;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|exists:company,id',
            'insurance_provider_id' => 'nullable|integer|exists:cm_insurance_provider,id',
            'title' => 'nullable|string',
            'note' => 'nullable|string',
            'account_code' => 'nullable|string',
            'plan_code' => 'nullable|string',
            'export_compare_configuration' => 'nullable|string',
            'premium' => 'nullable|numeric',
            'premium_computation' => 'nullable|string',
            'premium_variable' => 'nullable|string',
            'with_monthly' => 'nullable|boolean',
            'age_restriction' => 'nullable|string',
            'hierarchy_options' => 'nullable|string',
            'premium_restriction' => 'nullable|string',
            'principal_mbl' => 'nullable|numeric',
            'principal_room_and_board' => 'nullable|string',
            'dependent_mbl' => 'nullable|numeric',
            'dependent_room_and_board' => 'nullable|string',
            'with_address' => 'nullable|boolean',
            'with_skip_hierarchy' => 'nullable|boolean',
            'export_type' => 'nullable|string',
            'with_optout' => 'nullable|boolean',
            'status' => 'nullable|string',
        ];
    }
}
