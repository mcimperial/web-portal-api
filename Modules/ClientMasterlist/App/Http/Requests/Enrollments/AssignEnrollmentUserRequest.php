<?php

namespace Modules\ClientMasterlist\App\Http\Requests\Enrollments;

use Illuminate\Foundation\Http\FormRequest;

class AssignEnrollmentUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'enrollment_id' => 'required|integer|exists:cm_enrollment,id',
        ];
    }
}
