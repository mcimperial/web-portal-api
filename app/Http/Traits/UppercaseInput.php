<?php

namespace App\Http\Traits;

trait UppercaseInput
{
    /**
     * Convert all string fields in the given array to uppercase.
     *
     * @param array $data
     * @return array
     */
    public function uppercaseStrings(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = mb_strtoupper($value, 'UTF-8');
            }
        }
        return $data;
    }
}
