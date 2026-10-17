<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMasterlist\App\Models\BillingRecord;

class BillingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cm_billing_records')) {
            return response()->json([]);
        }

        $validated = $request->validate([
            'enrollment_id' => ['nullable', 'integer', 'exists:cm_enrollment,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BillingRecord::query()->with(['enrollment', 'importedBy']);

        if (!empty($validated['enrollment_id'])) {
            $query->where('enrollment_id', $validated['enrollment_id']);
        }

        $records = $query
            ->orderByDesc('id')
            ->limit($validated['per_page'] ?? 200)
            ->get();

        return response()->json($records);
    }

    public function store(Request $request): JsonResponse
    {
        if (!Schema::hasTable('cm_billing_records')) {
            return response()->json([
                'message' => 'Billing table is not installed yet. Run the billing migration first.',
            ], 503);
        }

        $validated = $request->validate([
            'enrollment_id' => ['required', 'integer', 'exists:cm_enrollment,id'],
            'source_file_name' => ['nullable', 'string', 'max:255'],
            'sheet_name' => ['nullable', 'string', 'max:255'],
            'columns' => ['required', 'array', 'min:1'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.source_row_number' => ['required', 'integer', 'min:1'],
            'rows.*.principal_id' => ['nullable', 'integer'],
            'rows.*.dependent_id' => ['nullable', 'integer'],
            'rows.*.row_data' => ['required', 'array'],
        ]);

        $importedRows = [];

        DB::transaction(function () use (&$importedRows, $validated, $request) {
            foreach ($validated['rows'] as $row) {
                $principalId = $row['principal_id'] ?? null;
                $dependentId = $row['dependent_id'] ?? null;

                $payload = [
                    'enrollment_id' => $validated['enrollment_id'],
                    'principal_id' => $principalId,
                    'dependent_id' => $dependentId,
                    'source_file_name' => $validated['source_file_name'] ?? null,
                    'sheet_name' => $validated['sheet_name'] ?? null,
                    'source_row_number' => $row['source_row_number'],
                    'column_names' => $validated['columns'],
                    'row_data' => $row['row_data'],
                    'imported_by_user_id' => $request->user()?->id,
                ];

                $hasMatchKey = !is_null($principalId) || !is_null($dependentId);

                if (!$hasMatchKey) {
                    $importedRows[] = BillingRecord::create($payload);
                    continue;
                }

                $lookup = [
                    'enrollment_id' => $validated['enrollment_id'],
                    'principal_id' => $principalId,
                    'dependent_id' => $dependentId,
                ];

                $importedRows[] = BillingRecord::updateOrCreate($lookup, $payload);
            }
        });

        return response()->json([
            'message' => 'Billing rows imported successfully.',
            'count' => count($importedRows),
            'records' => $importedRows,
        ], 201);
    }
}