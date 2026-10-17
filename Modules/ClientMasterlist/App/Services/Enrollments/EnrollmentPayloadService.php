<?php

namespace Modules\ClientMasterlist\App\Services\Enrollments;

use App\Http\Traits\UppercaseInput;
use Illuminate\Http\Request;

class EnrollmentPayloadService
{
    use UppercaseInput;

    private const INTEGER_FIELDS = [
        'company_id',
        'insurance_provider_id',
    ];

    private const RAW_INPUT_FIELDS = [
        'export_compare_configuration',
    ];

    public function prepare(array $validated, Request $request): array
    {
        $payload = $this->uppercaseStrings($validated);

        foreach (self::INTEGER_FIELDS as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = (int) $request->input($field);
            }
        }

        foreach (self::RAW_INPUT_FIELDS as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $request->input($field);
            }
        }

        return $payload;
    }
}
