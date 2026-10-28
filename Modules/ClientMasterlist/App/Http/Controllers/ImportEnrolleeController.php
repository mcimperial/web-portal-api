<?php

namespace Modules\ClientMasterlist\App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Dependent;
use Modules\ClientMasterlist\App\Models\HealthInsurance;
use Modules\ClientMasterlist\App\Models\Enrollment;
use Modules\ClientMasterlist\App\Models\InsuranceProvider;
use Modules\ClientMasterlist\App\Models\ImportLog;
use Modules\ClientMasterlist\App\Models\ImportTempData;
use Modules\ClientMasterlist\App\Models\PrincipalUnmappedColumn;
use Modules\ClientMasterlist\App\Models\ResignedImportAudit;
use App\Models\Company;
use Modules\ClientMasterlist\App\Models\ImportedEnrollmentFile;

use App\Http\Traits\DateSanitizer;
use App\Http\Traits\UppercaseInput;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class ImportEnrolleeController extends Controller
{
    use UppercaseInput, DateSanitizer;

    /**
     * Store detailed import logging information
     */
    private $importLog = [
        'principals' => [],
        'dependents' => [],
        'source_file' => null,
        'summary' => [
            'total_principals' => 0,
            'total_dependents' => 0,
            'principals_created' => 0,
            'principals_updated' => 0,
            'dependents_created' => 0,
            'dependents_updated' => 0,
        ]
    ];

    /**
     * Queue of principals that became RESIGNED during this import run.
     * Persisted to cm_resigned_import_audits once import_log_id is available.
     */
    private $resignedAuditCandidates = [];

    private function resetResignedAuditCandidates(): void
    {
        $this->resignedAuditCandidates = [];
    }

    private function normalizeDateForAudit($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return date('Y-m-d', strtotime((string) $value));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function queueResignedImportAuditCandidate(?Enrollee $principal, array $enrolleeData, array $healthInsuranceData, int $enrollmentId): void
    {
        if (!$principal || !$principal->id) {
            return;
        }

        if (!$this->isResignedMember($principal->enrollment_status ?? null, $principal->status ?? null)) {
            return;
        }

        $resignedDate =
            $this->normalizeDateForAudit($principal->employment_end_date ?? null)
            ?? $this->normalizeDateForAudit($healthInsuranceData['coverage_end_date'] ?? null)
            ?? $this->normalizeDateForAudit($enrolleeData['employment_end_date'] ?? null)
            ?? now()->toDateString();

        $this->resignedAuditCandidates[(string) $principal->id] = [
            'principal_id' => $principal->id,
            'enrollment_id' => $enrollmentId,
            'employee_id' => $principal->employee_id,
            'first_name' => $principal->first_name,
            'last_name' => $principal->last_name,
            'resigned_date' => $resignedDate,
        ];
    }

    private function persistResignedImportAudits(?int $importLogId): void
    {
        if (empty($this->resignedAuditCandidates)) {
            return;
        }

        $sourceFile = $this->importLog['source_file'] ?? [];
        $enrollmentCache = [];

        foreach ($this->resignedAuditCandidates as $candidate) {
            $principalId = (int) ($candidate['principal_id'] ?? 0);
            if ($principalId <= 0) {
                continue;
            }

            $alreadyRecorded = ResignedImportAudit::where('principal_id', $principalId)->exists();
            if ($alreadyRecorded) {
                continue;
            }

            $enrollmentId = isset($candidate['enrollment_id']) ? (int) $candidate['enrollment_id'] : null;
            if ($enrollmentId && !isset($enrollmentCache[$enrollmentId])) {
                $enrollmentCache[$enrollmentId] = Enrollment::with(['company', 'insuranceProvider'])->find($enrollmentId);
            }

            $enrollment = $enrollmentId ? ($enrollmentCache[$enrollmentId] ?? null) : null;

            ResignedImportAudit::create([
                'principal_id' => $principalId,
                'enrollment_id' => $enrollmentId,
                'import_log_id' => $importLogId,
                'employee_id' => $candidate['employee_id'] ?? null,
                'first_name' => $candidate['first_name'] ?? null,
                'last_name' => $candidate['last_name'] ?? null,
                'resigned_date' => $candidate['resigned_date'] ?? now()->toDateString(),
                'source_file_original_name' => $sourceFile['original_name'] ?? null,
                'source_file_stored_name' => $sourceFile['stored_name'] ?? null,
                'source_file_path' => $sourceFile['path'] ?? null,
                'source_file_url' => $sourceFile['url'] ?? null,
                'source_company_code' => $enrollment?->company?->company_code ?? ($sourceFile['company_code'] ?? null),
                'source_provider_title' => $enrollment?->insuranceProvider?->title ?? ($sourceFile['provider_title'] ?? null),
                'recorded_at' => now(),
            ]);
        }
    }

    /**
     * Make sure we have a live DB connection before starting a
     * potentially long-running bulk import, and raise the session-level
     * timeouts so MySQL doesn't close the connection mid-import
     * ("MySQL server has gone away").
     */
    private function ensureStableDbConnection(): void
    {
        try {
            // Force (re)connection now rather than lazily mid-transaction.
            DB::connection()->getPdo();
            DB::statement('SET SESSION wait_timeout = 28800, interactive_timeout = 28800');
        } catch (\Throwable $e) {
            Log::warning('Unable to prepare DB connection for bulk import', [
                'error' => $e->getMessage(),
            ]);

            try {
                DB::reconnect();
            } catch (\Throwable $reconnectError) {
                Log::error('Failed to reconnect to database before import', [
                    'error' => $reconnectError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Determine whether an exception represents a lost MySQL connection
     * (error 2006 "MySQL server has gone away" or 2013 "Lost connection").
     */
    private function isConnectionLostError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'Lost connection')
            || str_contains($message, '2006')
            || str_contains($message, '2013');
    }

    private function connectionLostMessage(): string
    {
        return 'Import failed because the database connection was lost during processing (the file may be too large or the import took too long). Please try again, or split the file into smaller batches.';
    }

    /**
     * Parse the delimited "unmapped_columns" text (e.g.
     * "Column A: value || Column B: value") into individual
     * column_name/column_value pairs and persist them to the
     * cm_principal_unmapped_columns table for the given principal.
     *
     * Any previously stored rows for the principal are replaced so the
     * table always reflects the most recent import.
     */
    private function syncUnmappedColumnValues(Enrollee $principal, ?string $unmappedColumnsText): void
    {
        if (empty(trim((string) $unmappedColumnsText))) {
            return;
        }

        $rows = [];
        $pairs = explode('||', $unmappedColumnsText);

        foreach ($pairs as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            // Split on the first ": " only, since the value itself may
            // legitimately contain a colon.
            $parts = explode(':', $pair, 2);
            $columnName = trim($parts[0] ?? '');
            $columnValue = trim($parts[1] ?? '');

            if ($columnName === '') {
                continue;
            }

            $rows[] = [
                'principal_id' => $principal->id,
                'column_name' => $columnName,
                'column_value' => $columnValue,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            return;
        }

        PrincipalUnmappedColumn::where('principal_id', $principal->id)->delete();
        PrincipalUnmappedColumn::insert($rows);
    }

    /**
     * Roll back all open transaction levels, guarding against the rollback
     * itself throwing when the underlying connection has already been
     * dropped by MySQL. Attempts a reconnect afterwards so the connection
     * is left in a usable state for subsequent requests.
     */
    private function safeRollback(): void
    {
        try {
            $transactionLevel = DB::transactionLevel();
            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::rollBack();
            }
        } catch (\Throwable $rollbackError) {
            Log::error('Failed to rollback transaction (connection may already be lost)', [
                'error' => $rollbackError->getMessage(),
            ]);

            try {
                DB::reconnect();
            } catch (\Throwable $reconnectError) {
                Log::error('Failed to reconnect to database after rollback failure', [
                    'error' => $reconnectError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Add principal log entry with field mapping
     */
    private function logPrincipal(string $employeeId, string $action, array $enrolleeData, array $changes = [], array $healthInsuranceData = []): void
    {
        $this->importLog['principals'][] = [
            'employee_id' => $employeeId,
            'action' => $action,
            'enrollee_fields' => [
                'first_name' => $enrolleeData['first_name'] ?? null,
                'last_name' => $enrolleeData['last_name'] ?? null,
                'middle_name' => $enrolleeData['middle_name'] ?? null,
                'birth_date' => $enrolleeData['birth_date'] ?? null,
                'gender' => $enrolleeData['gender'] ?? null,
                'employment_start_date' => $enrolleeData['employment_start_date'] ?? null,
                'employment_end_date' => $enrolleeData['employment_end_date'] ?? null,
                'email1' => $enrolleeData['email1'] ?? null,
                'phone1' => $enrolleeData['phone1'] ?? null,
                'address' => $enrolleeData['address'] ?? null,
                'department' => $enrolleeData['department'] ?? null,
                'position' => $enrolleeData['position'] ?? null,
                'marital_status' => $enrolleeData['marital_status'] ?? null,
                'nationality' => $enrolleeData['nationality'] ?? null,
                'enrollment_status' => $enrolleeData['enrollment_status'] ?? null,
            ],
            'health_insurance_fields' => [
                'certificate_number' => $healthInsuranceData['certificate_number'] ?? null,
                'plan' => $healthInsuranceData['plan'] ?? null,
                'premium' => $healthInsuranceData['premium'] ?? null,
                'coverage_start_date' => $healthInsuranceData['coverage_start_date'] ?? null,
                'coverage_end_date' => $healthInsuranceData['coverage_end_date'] ?? null,
                'is_company_paid' => $healthInsuranceData['is_company_paid'] ?? null,
                'is_renewal' => $healthInsuranceData['is_renewal'] ?? null,
                'is_skipping' => $healthInsuranceData['is_skipping'] ?? null,
                'reason_for_skipping' => $healthInsuranceData['reason_for_skipping'] ?? null,
            ],
            'changes' => $changes,
            'timestamp' => now()->toDateTimeString(),
        ];

        if ($action === 'created') {
            $this->importLog['summary']['principals_created']++;
        } elseif ($action === 'updated' && !empty($changes)) {
            $this->importLog['summary']['principals_updated']++;
        }
    }

    /**
     * Add dependent log entry with field mapping
     */
    private function logDependent(int $principalId, string $employeeId, string $action, array $dependentData, array $changes = [], array $healthInsuranceData = []): void
    {
        $this->importLog['dependents'][] = [
            'principal_id' => $principalId,
            'principal_employee_id' => $employeeId,
            'action' => $action,
            'dependent_fields' => [
                'first_name' => $dependentData['first_name'] ?? null,
                'last_name' => $dependentData['last_name'] ?? null,
                'middle_name' => $dependentData['middle_name'] ?? null,
                'relation' => $dependentData['relation'] ?? null,
                'birth_date' => $dependentData['birth_date'] ?? null,
                'gender' => $dependentData['gender'] ?? null,
                'marital_status' => $dependentData['marital_status'] ?? null,
                'nationality' => $dependentData['nationality'] ?? null,
                'enrollment_status' => $dependentData['enrollment_status'] ?? null,
            ],
            'health_insurance_fields' => [
                'certificate_number' => $healthInsuranceData['certificate_number'] ?? null,
                'plan' => $healthInsuranceData['plan'] ?? null,
                'premium' => $healthInsuranceData['premium'] ?? null,
                'coverage_start_date' => $healthInsuranceData['coverage_start_date'] ?? null,
                'coverage_end_date' => $healthInsuranceData['coverage_end_date'] ?? null,
                'is_company_paid' => $healthInsuranceData['is_company_paid'] ?? null,
                'is_skipping' => $healthInsuranceData['is_skipping'] ?? null,
            ],
            'changes' => $changes,
            'timestamp' => now()->toDateTimeString(),
        ];

        if ($action === 'created') {
            $this->importLog['summary']['dependents_created']++;
        } elseif ($action === 'updated' && !empty($changes)) {
            $this->importLog['summary']['dependents_updated']++;
        }
    }

    /**
     * Save import log to database
     */
    private function saveImportLog(?int $enrollmentId, string $dateFormat = 'auto', string $confidence = '0%', string $status = 'success', string $errorMessage = null): ImportLog
    {
        $importDetails = $this->buildStorableImportDetails();

        $importLog = ImportLog::create([
            'enrollment_id' => $enrollmentId,
            'import_date' => now(),
            'total_principals' => $this->importLog['summary']['total_principals'],
            'total_dependents' => $this->importLog['summary']['total_dependents'],
            'principals_created' => $this->importLog['summary']['principals_created'],
            'principals_updated' => $this->importLog['summary']['principals_updated'],
            'dependents_created' => $this->importLog['summary']['dependents_created'],
            'dependents_updated' => $this->importLog['summary']['dependents_updated'],
            'import_details' => $importDetails,
            'date_format_detected' => $dateFormat,
            'date_format_confidence' => $confidence,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);

        return $importLog;
    }

    /**
     * Prepare the import log payload for storage in the `import_details` JSON
     * column while avoiding oversized packets for large imports.
     */
    private function buildStorableImportDetails(): array
    {
        $fullDetails = $this->importLog;

        $maxBytes = 2 * 1024 * 1024;
        $encoded = json_encode($fullDetails);

        if ($encoded !== false && strlen($encoded) <= $maxBytes) {
            return $fullDetails;
        }

        $fileName = 'import-logs/full-log-' . now()->format('Y-m-d_His') . '-' . Str::random(8) . '.json';

        try {
            Storage::disk('local')->put($fileName, $encoded !== false ? $encoded : json_encode([
                'error' => 'Failed to encode full import log',
            ]));
        } catch (\Throwable $e) {
            Log::error('Failed to write full import log to disk', ['error' => $e->getMessage()]);
            $fileName = null;
        }

        $maxInlineEntries = 200;
        $principals = $fullDetails['principals'] ?? [];
        $dependents = $fullDetails['dependents'] ?? [];

        $truncatedPrincipals = array_slice($principals, 0, $maxInlineEntries);
        $truncatedDependents = array_slice($dependents, 0, $maxInlineEntries);

        Log::warning('Import log details truncated before saving to database due to size', [
            'encoded_size_bytes' => $encoded !== false ? strlen($encoded) : null,
            'max_bytes' => $maxBytes,
            'total_principals_logged' => count($principals),
            'total_dependents_logged' => count($dependents),
            'inline_principals_kept' => count($truncatedPrincipals),
            'inline_dependents_kept' => count($truncatedDependents),
            'full_log_file' => $fileName,
        ]);

        return array_merge($fullDetails, [
            'principals' => $truncatedPrincipals,
            'dependents' => $truncatedDependents,
            'truncated' => true,
            'truncated_principals_omitted' => max(0, count($principals) - count($truncatedPrincipals)),
            'truncated_dependents_omitted' => max(0, count($dependents) - count($truncatedDependents)),
            'full_log_file' => $fileName,
        ]);
    }

    /**
     * Persist a normalized record of the uploaded source file for reuse in the
     * billing history/download UI. This covers both regular enrollment imports
     * and multi-company / multi-provider imports that have no single enrollment_id.
     */
    private function persistImportedEnrollmentFileRecord(?int $importLogId, ?int $enrollmentId, string $sourceType = 'regular'): void
    {
        $sourceFile = $this->importLog['source_file'] ?? null;

        if (!$sourceFile || empty($sourceFile['url'])) {
            return;
        }

        $uploadedAt = $sourceFile['uploaded_at'] ?? null;

        ImportedEnrollmentFile::updateOrCreate(
            [
                'import_log_id' => $importLogId,
                'file_url' => $sourceFile['url'],
            ],
            [
                'enrollment_id' => $enrollmentId,
                'source_type' => $sourceType,
                'company_code' => $sourceFile['company_code'] ?? null,
                'provider_title' => $sourceFile['provider_title'] ?? null,
                'original_name' => $sourceFile['original_name'] ?? null,
                'stored_name' => $sourceFile['stored_name'] ?? null,
                'file_path' => $sourceFile['path'] ?? null,
                'file_url' => $sourceFile['url'],
                'mime_type' => $sourceFile['mime_type'] ?? null,
                'uploaded_at' => $uploadedAt ? now()->parse($uploadedAt) : now(),
            ]
        );
    }

    /**
     * Analyze and sanitize dates in bulk import data with improved format detection
     * 
     * @param array $importData Array of import rows
     * @param array $dateFields Array of date field names to process
     * @return array Analysis results and sanitized data
     */
    private function analyzeBulkDates(array $importData, array $dateFields)
    {
        // Collect all date values for analysis
        $allDates = [];
        foreach ($importData as $row) {
            foreach ($dateFields as $field) {
                if (!empty($row[$field])) {
                    $allDates[] = $row[$field];
                }
            }
        }

        // Analyze date formats
        $analysis = $this->analyzeDateFormats($allDates);

        // Log the aggressive enforcement decision
        if ($analysis['dmy_indicators'] > 0) {
            Log::info("AGGRESSIVE DD/MM FORMAT ENFORCEMENT ACTIVATED", [
                'total_dates_analyzed' => count($allDates),
                'dd_mm_indicators_found' => $analysis['dmy_indicators'],
                'ambiguous_dates_forced_to_dd_mm' => $analysis['ambiguous_dates'],
                'enforcement_reason' => 'Found DD/MM indicators - forcing ALL dates to DD/MM format'
            ]);
        }

        Log::info("Bulk date analysis completed", [
            'total_dates_analyzed' => count($allDates),
            'recommended_format' => $analysis['recommended_format'],
            'confidence' => $analysis['confidence'],
            'dmy_indicators' => $analysis['dmy_indicators'],
            'mdy_indicators' => $analysis['mdy_indicators'],
            'ambiguous_dates' => $analysis['ambiguous_dates']
        ]);

        return $analysis;
    }

    /**
     * Sanitize dates using detected format preference
     * 
     * @param mixed $date Date value to sanitize
     * @param string $detectedFormat Format preference from bulk analysis
     * @return string|null Sanitized date or null
     */
    private function sanitizeDateWithFormat($date, $detectedFormat)
    {
        if (empty($date)) return null;

        $sanitized = $this->sanitizeDate($date, $detectedFormat);

        if (!$sanitized) {
            Log::warning("Failed to sanitize date", [
                'original_date' => $date,
                'detected_format' => $detectedFormat
            ]);
        }

        return $sanitized;
    }

    /**
     * Normalize values for comparison to avoid unnecessary updates
     */
    private function normalizeValue($value)
    {
        // Handle null and empty values
        if (is_null($value) || $value === '' || $value === 'NULL' || $value === 'null') {
            return null;
        }

        // Handle boolean values consistently
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        // Convert to string and trim whitespace
        $normalized = trim((string) $value);

        // Handle empty string after trimming
        if ($normalized === '') {
            return null;
        }

        // Handle string representations of booleans
        $upperNormalized = strtoupper($normalized);
        if (in_array($upperNormalized, ['TRUE', 'FALSE', 'YES', 'NO'])) {
            return in_array($upperNormalized, ['TRUE', 'YES']) ? 1 : 0;
        }

        // Handle numeric values (including decimal precision issues).
        // NOTE: is_numeric() also accepts scientific notation like "39E5864",
        // which is a common false-positive for alphanumeric IDs (e.g. employee
        // IDs such as "39E5864"). Casting such a value to (int) overflows and
        // triggers "float-string is not representable as int" errors on PHP
        // 8.1+, plus silently corrupts the ID. Only treat plain decimal
        // numbers (no exponent notation) as numeric here.
        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized) === 1) {
            $floatVal = (float) $normalized;

            // Guard against values outside the safe integer range (or
            // non-finite results) before casting to int, to avoid the
            // "float-string is not representable as int" error.
            if ((float) $normalized == (int) $normalized
                && is_finite($floatVal)
                && abs($floatVal) <= PHP_INT_MAX
            ) {
                return (int) $normalized;
            } else {
                // Round to 2 decimal places to avoid precision issues
                return round($floatVal, 2);
            }
        }

        // Convert to uppercase for string comparison (since we use uppercaseStrings).
        // Use the same strict decimal-number check as above (not is_numeric)
        // so IDs like "39E5864" are treated as plain strings and uppercased,
        // rather than being skipped because is_numeric() considers them
        // scientific notation.
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            $normalized = strtoupper($normalized);
        }

        return $normalized;
    }

    /**
     * Decode enrollees payload from JSON or array input.
     */
    private function parseEnrolleesInput(Request $request): array
    {
        $enrollees = $request->input('enrollees', []);

        if (is_string($enrollees)) {
            $decoded = json_decode($enrollees, true);
            return is_array($decoded)
                ? $this->normalizeImportPayloadValues($decoded)
                : [];
        }

        return is_array($enrollees)
            ? $this->normalizeImportPayloadValues($enrollees)
            : [];
    }

    /**
     * Recursively normalize import payload values.
     * Converts empty/whitespace-only strings to null while preserving
     * non-empty strings and scalar values.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeImportPayloadValues($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $nestedValue) {
                $value[$key] = $this->normalizeImportPayloadValues($nestedValue);
            }

            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    /**
     * Decode optional import column headers payload from JSON or array input.
     */
    private function parseImportColumnsInput(Request $request): array
    {
        $columns = $request->input('import_columns', []);

        if (is_string($columns)) {
            $decoded = json_decode($columns, true);
            $columns = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($columns)) {
            return [];
        }

        $normalized = [];

        foreach ($columns as $column) {
            $name = trim((string) $column);

            if ($name !== '') {
                $normalized[] = $name;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Persist import payload rows into temporary storage for one enrollment.
     *
     * Keeps only one import date worth of rows by deleting previous rows first.
     */
    private function storeTempImportRowsForEnrollment(int $enrollmentId, array $rows, array $importColumns = []): void
    {
        $importDate = now()->toDateString();

        ImportTempData::where('enrollment_id', $enrollmentId)
            ->whereDate('import_date', $importDate)
            ->delete();

        if (empty($rows)) {
            return;
        }

        $detectedColumns = $importColumns;

        if (empty($detectedColumns)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $detectedColumns = array_values(array_unique(array_merge($detectedColumns, array_keys($row))));
            }
        }

        $payload = [];
        $now = now();

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalizedRow = [];

            foreach ($row as $columnName => $columnValue) {
                $normalizedRow[$columnName] = $this->normalizeTempStorageValue((string) $columnName, $columnValue);
            }

            $payload[] = [
                'enrollment_id' => $enrollmentId,
                'import_date' => $importDate,
                'row_number' => $index + 1,
                'column_names' => json_encode($detectedColumns),
                'row_data' => json_encode($normalizedRow),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($payload)) {
            $this->insertTempImportPayloadInBatches($payload, $enrollmentId);
        }
    }

    /**
     * Insert temp-import payload in manageable batches to avoid
     * max_allowed_packet errors from very large multi-row SQL statements.
     */
    private function insertTempImportPayloadInBatches(array $payload, int $enrollmentId): void
    {
        $batchSize = 100;

        foreach (array_chunk($payload, $batchSize) as $batch) {
            try {
                ImportTempData::insert($batch);
            } catch (QueryException $e) {
                if (!$this->isPacketTooLargeError($e)) {
                    throw $e;
                }

                Log::warning('Temp import batch exceeded max_allowed_packet; retrying row-by-row', [
                    'enrollment_id' => $enrollmentId,
                    'batch_row_count' => count($batch),
                    'error' => $e->getMessage(),
                ]);

                foreach ($batch as $rowPayload) {
                    try {
                        ImportTempData::insert([$rowPayload]);
                    } catch (QueryException $rowException) {
                        if (!$this->isPacketTooLargeError($rowException)) {
                            throw $rowException;
                        }

                        Log::error('Single temp import row exceeds max_allowed_packet', [
                            'enrollment_id' => $enrollmentId,
                            'row_number' => $rowPayload['row_number'] ?? null,
                            'error' => $rowException->getMessage(),
                        ]);

                        throw new \Exception(
                            'Import row ' . ($rowPayload['row_number'] ?? 'N/A') . ' is too large for current MySQL max_allowed_packet. '
                            . 'Please reduce oversized cell content or increase max_allowed_packet.'
                        );
                    }
                }
            }
        }
    }

    /**
     * Detect MySQL packet-size errors (1153 / max_allowed_packet).
     */
    private function isPacketTooLargeError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'max_allowed_packet')
            || str_contains($message, 'packet bigger')
            || str_contains($message, 'error 1153')
            || str_contains($message, 'got a packet bigger');
    }

    /**
     * Persist temporary import rows grouped by enrollment.
     */
    private function storeTempImportRowsByEnrollment(array $rowsByEnrollment, array $importColumns = []): void
    {
        foreach ($rowsByEnrollment as $enrollmentId => $rows) {
            $currentEnrollmentId = (int) $enrollmentId;

            if ($currentEnrollmentId <= 0) {
                continue;
            }

            $this->storeTempImportRowsForEnrollment($currentEnrollmentId, $rows, $importColumns);
        }
    }

    /**
     * Identify whether a relation value should be treated as principal data.
     */
    private function isPrincipalRelation(?string $relation): bool
    {
        $normalized = strtoupper(trim((string) $relation));

        return in_array($normalized, ['', 'PRINCIPAL', 'EMPLOYEE', 'EMPLOYEES'], true);
    }

    /**
     * Prepare fields used for temp-vs-existing comparison.
     */
    private function getComparisonFields(): array
    {
        return [
            'first_name',
            'last_name',
            'middle_name',
            'suffix',
            'birth_date',
            'gender',
            'email1',
            'email2',
            'phone1',
            'phone2',
            'address',
            'department',
            'position',
            'employment_start_date',
            'employment_end_date',
            'enrollment_status',
            'status',
        ];
    }

    /**
     * Normalize date-like values to Y-m-d where possible.
     */
    private function normalizeComparableValue(string $field, $value)
    {
        $normalized = $this->normalizeValue($value);

        if (is_null($normalized)) {
            return null;
        }

        if (in_array($field, ['birth_date', 'employment_start_date', 'employment_end_date'], true)) {
            $timestamp = strtotime((string) $normalized);

            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        return $normalized;
    }

    /**
     * Normalize temp-import row values before persisting in JSON storage.
     * Date-like columns are converted to Y-m-d.
     */
    private function normalizeTempStorageValue(string $column, $value)
    {
        if (is_null($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!$this->isDateLikeColumnName($column)) {
            return $value;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return $value;
        }

        $raw = trim((string) $value);

        if ($raw === '' || strtoupper($raw) === 'NULL') {
            return null;
        }

        if (!preg_match('/^\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}(?:[T\s]\d{1,2}:\d{2}(?::\d{2})?)?$/', $raw)
            && !preg_match('/^\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}(?:[T\s]\d{1,2}:\d{2}(?::\d{2})?)?$/', $raw)) {
            return $value;
        }

        $normalizedDate = $this->parseDateStringToYmd($raw);

        if (is_null($normalizedDate)) {
            return $value;
        }

        return $normalizedDate;
    }

    /**
     * Detect whether a temp column name likely represents a date field.
     */
    private function isDateLikeColumnName(string $column): bool
    {
        $normalized = strtolower(trim($column));

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['dob', 'birthdate'], true)) {
            return true;
        }

        return str_contains($normalized, 'date');
    }

    /**
     * Determine whether a value should be treated as null/empty for
     * temp-column availability checks.
     */
    private function isNullLikeValue($value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === ''
                || strtoupper($trimmed) === 'NULL';
        }

        return false;
    }

    /**
     * Normalize temp display values: only date-like columns are forced to Y-m-d.
     */
    private function normalizeTempDisplayValue(string $column, $value)
    {
        if (is_null($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || strtoupper($trimmed) === 'NULL') {
            return null;
        }

        if (!$this->isDateLikeColumnName($column)) {
            return $trimmed;
        }

        $normalizedDate = $this->parseDateStringToYmd($trimmed);

        if (!is_null($normalizedDate)) {
            return $normalizedDate;
        }

        return $trimmed;
    }

    /**
     * Parse supported date strings and return strict Y-m-d.
     */
    private function parseDateStringToYmd(string $raw): ?string
    {
        $candidate = trim($raw);

        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{2})(?:[T\s](\d{1,2}:\d{2}(?::\d{2})?))?$/', $candidate, $matches) === 1) {
            $firstSegmentAsYear = (int) $matches[1];
            $month = (int) $matches[2];
            $twoDigitYear = (int) $matches[3];

            if ($firstSegmentAsYear >= 1 && $firstSegmentAsYear <= 31 && $month >= 1 && $month <= 12) {
                $day = $firstSegmentAsYear;
                $timePart = isset($matches[4]) && $matches[4] !== '' ? ' ' . $matches[4] : '';
                $reconstructed = sprintf('%d-%d-%02d%s', $day, $month, $twoDigitYear, $timePart);

                foreach (['j-n-y H:i:s', 'j-n-y H:i', 'j-n-y'] as $legacyFormat) {
                    $parsedLegacy = \DateTime::createFromFormat('!' . $legacyFormat, $reconstructed);

                    if ($parsedLegacy === false) {
                        continue;
                    }

                    $legacyErrors = \DateTime::getLastErrors();
                    $legacyErrorCount = is_array($legacyErrors) ? ($legacyErrors['error_count'] ?? 0) : 0;
                    $legacyWarningCount = is_array($legacyErrors) ? ($legacyErrors['warning_count'] ?? 0) : 0;

                    if ($legacyErrorCount === 0 && $legacyWarningCount === 0) {
                        return $parsedLegacy->format('Y-m-d');
                    }
                }
            }
        }

        $yearFirstFormats = [
            'Y-m-d',
            'Y/n/j',
            'Y.m.d',
            'Y-m-d H:i',
            'Y-m-d H:i:s',
            'Y/n/j H:i',
            'Y/n/j H:i:s',
            'Y.m.d H:i',
            'Y.m.d H:i:s',
            'Y-m-d\\TH:i',
            'Y-m-d\\TH:i:s',
            'Y-m-d\\TH:i:sP',
        ];

        $dayMonthYearFourDigitFormats = [
            'd/m/Y',
            'j/n/Y',
            'd-m-Y',
            'j-n-Y',
            'd.m.Y',
            'j.n.Y',
            'd/m/Y H:i',
            'd/m/Y H:i:s',
            'j/n/Y H:i',
            'j/n/Y H:i:s',
            'd-m-Y H:i',
            'd-m-Y H:i:s',
            'j-n-Y H:i',
            'j-n-Y H:i:s',
        ];

        $dayMonthYearTwoDigitFormats = [
            'd/m/y',
            'j/n/y',
            'd-m-y',
            'j-n-y',
            'd.m.y',
            'j.n.y',
            'd/m/y H:i',
            'd/m/y H:i:s',
            'j/n/y H:i',
            'j/n/y H:i:s',
            'd-m-y H:i',
            'd-m-y H:i:s',
            'j-n-y H:i',
            'j-n-y H:i:s',
        ];

        $monthDayYearFourDigitFormats = [
            'm/d/Y',
            'n/j/Y',
            'm-d-Y',
            'n-j-Y',
            'm.d.Y',
            'n.j.Y',
            'm/d/Y H:i',
            'm/d/Y H:i:s',
            'n/j/Y H:i',
            'n/j/Y H:i:s',
        ];

        $monthDayYearTwoDigitFormats = [
            'm/d/y',
            'n/j/y',
            'm-d-y',
            'n-j-y',
            'm.d.y',
            'n.j.y',
            'm/d/y H:i',
            'm/d/y H:i:s',
            'n/j/y H:i',
            'n/j/y H:i:s',
        ];

        $isYearFirstCandidate = preg_match('/^\d{4}[-\/.]/', $candidate) === 1;
        $hasFourDigitTrailingYear = preg_match('/[-\/.]\d{4}(?:[T\s]|$)/', $candidate) === 1;

        if ($isYearFirstCandidate) {
            $formats = $yearFirstFormats;
        } elseif ($hasFourDigitTrailingYear) {
            $formats = array_merge($dayMonthYearFourDigitFormats, $monthDayYearFourDigitFormats);
        } else {
            $formats = array_merge($dayMonthYearTwoDigitFormats, $monthDayYearTwoDigitFormats);
        }

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat('!' . $format, $candidate);

            if ($parsed === false) {
                continue;
            }

            $errors = \DateTime::getLastErrors();
            $errorCount = is_array($errors) ? ($errors['error_count'] ?? 0) : 0;
            $warningCount = is_array($errors) ? ($errors['warning_count'] ?? 0) : 0;

            if ($errorCount === 0 && $warningCount === 0) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Normalize status-like values for case-insensitive comparisons.
     */
    private function normalizeStatusValue($value): string
    {
        if (is_null($value)) {
            return '';
        }

        $normalized = strtoupper(trim((string) $value));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    /**
     * Get normalized notes token from a temp row.
     */
    private function normalizeTempNotesToken(array $tempRow): string
    {
        $rawNotes = $tempRow['notes']
            ?? $tempRow['note']
            ?? $tempRow['remarks']
            ?? $tempRow['remark']
            ?? null;

        if (is_null($rawNotes)) {
            return '';
        }

        $normalized = strtoupper(trim((string) $rawNotes));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    /**
     * Determine whether a temp notes mapping matches one of the allowed values.
     */
    private function isTempNotesMapped(array $tempRow, array $allowedMappings): bool
    {
        $normalizedNotes = $this->normalizeTempNotesToken($tempRow);

        if ($normalizedNotes === '') {
            return false;
        }

        $allowed = array_map(fn ($value) => $this->normalizeStatusValue($value), $allowedMappings);

        return in_array($normalizedNotes, $allowed, true);
    }

    /**
     * Existing is ACTIVE/APPROVED only when enrollment_status is APPROVED.
     */
    private function isExistingApprovedMember($enrollmentStatus): bool
    {
        return $this->normalizeStatusValue($enrollmentStatus) === 'APPROVED';
    }

    /**
     * Existing NOT ENROLLED statuses are pending/submitted/submitted-personal-information/for-approval.
     */
    private function isExistingNotEnrolledMember($enrollmentStatus, $status = null): bool
    {
        $normalizedEnrollment = $this->normalizeStatusValue($enrollmentStatus);
        $normalizedStatus = $this->normalizeStatusValue($status);

        $allowedNotEnrolled = [
            'PENDING',
            'SUBMITTED',
            'SUBMITTED-PERSONAL-INFORMATION',
            'FOR APPROVAL',
        ];

        return in_array($normalizedEnrollment, $allowedNotEnrolled, true)
            || in_array($normalizedStatus, $allowedNotEnrolled, true);
    }

    /**
     * Determine whether a status combination should be counted as resigned.
     */
    private function isResignedMember($enrollmentStatus, $status): bool
    {
        $enrollmentNormalized = $this->normalizeStatusValue($enrollmentStatus);
        $statusNormalized = $this->normalizeStatusValue($status);

        return str_contains($enrollmentNormalized, 'RESIGN')
            || str_contains($statusNormalized, 'RESIGN');
    }

    /**
     * Existing resigned principals are immutable during imports.
     *
     * Locking is based on enrollment_status only so members can be
     * imported again after being moved back to non-resigned statuses
     * (e.g. PENDING).
     */
    private function isLockedResignedPrincipal(?Enrollee $principal): bool
    {
        if (!$principal) {
            return false;
        }

        $enrollmentNormalized = $this->normalizeStatusValue($principal->enrollment_status ?? null);

        return str_contains($enrollmentNormalized, 'RESIGN');
    }

    /**
     * Determine whether a status combination should be counted as not enrolled.
     */
    private function isNotEnrolledMember($enrollmentStatus, $status): bool
    {
        $enrollmentNormalized = $this->normalizeStatusValue($enrollmentStatus);
        $statusNormalized = $this->normalizeStatusValue($status);

        return str_contains($enrollmentNormalized, 'NOT ENROLL')
            || str_contains($statusNormalized, 'NOT ENROLL');
    }

    /**
     * Temp ACTIVE/APPROVED is mapped via notes=enrolled.
     */
    private function isTempApprovedMember(array $tempRow): bool
    {
        return $this->isTempNotesMapped($tempRow, ['enrolled']);
    }

    /**
     * Temp NOT ENROLLED mappings come from notes value.
     */
    private function isTempNotEnrolledMember(array $tempRow): bool
    {
        return $this->isTempNotesMapped($tempRow, ['not_enrolled', 'awaiting_enrollment', 'opted_out']);
    }

    /**
     * Determine whether a temp row matches active search/filter criteria.
     */
    private function tempRowMatchesSearch(array $tempRow, string $search, string $searchBy): bool
    {
        $needle = strtolower(trim($search));

        if ($needle === '') {
            return true;
        }

        $contains = function ($value) use ($needle): bool {
            if (is_null($value)) {
                return false;
            }

            return str_contains(strtolower(trim((string) $value)), $needle);
        };

        $matchesAny = function (array $values) use ($contains): bool {
            foreach ($values as $value) {
                if ($contains($value)) {
                    return true;
                }
            }

            return false;
        };

        $nameValues = [
            $tempRow['full_name'] ?? null,
            $tempRow['name'] ?? null,
            trim(implode(' ', array_filter([
                $tempRow['first_name'] ?? null,
                $tempRow['middle_name'] ?? null,
                $tempRow['last_name'] ?? null,
                $tempRow['suffix'] ?? null,
            ], fn ($value) => !empty(trim((string) $value))))),
            trim(implode(', ', array_filter([
                $tempRow['last_name'] ?? null,
                trim(implode(' ', array_filter([
                    $tempRow['first_name'] ?? null,
                    $tempRow['middle_name'] ?? null,
                    $tempRow['suffix'] ?? null,
                ], fn ($value) => !empty(trim((string) $value))))) ?: null,
            ]))),
        ];

        $certificateValues = [
            $tempRow['certificate_number'] ?? null,
            $tempRow['certificate_no'] ?? null,
            $tempRow['certificate no'] ?? null,
            $tempRow['cert_no'] ?? null,
            $tempRow['cert_number'] ?? null,
        ];

        if ($searchBy === 'employee_id') {
            return $contains($tempRow['employee_id'] ?? null);
        }

        if ($searchBy === 'name') {
            return $matchesAny($nameValues);
        }

        if ($searchBy === 'department') {
            return $contains($tempRow['department'] ?? null);
        }

        if ($searchBy === 'certificate_number') {
            return $matchesAny($certificateValues);
        }

        return $contains($tempRow['employee_id'] ?? null)
            || $matchesAny($nameValues)
            || $contains($tempRow['department'] ?? null)
            || $matchesAny($certificateValues);
    }

    /**
     * Temp row resigned rule: employee_end_date/employment_end_date <= today.
     */
    private function isTempRowResigned(array $tempRow, string $todayDate): bool
    {
        if ($this->isResignedMember($tempRow['enrollment_status'] ?? null, $tempRow['status'] ?? null)) {
            return true;
        }

        $endDateRaw = $tempRow['employee_end_date'] ?? $tempRow['employment_end_date'] ?? null;

        if (is_null($endDateRaw)) {
            return false;
        }

        if (!is_string($endDateRaw) && !is_numeric($endDateRaw)) {
            return false;
        }

        $normalizedEndDate = $this->parseDateStringToYmd((string) $endDateRaw);

        if (is_null($normalizedEndDate)) {
            return false;
        }

        return $normalizedEndDate <= $todayDate;
    }

    /**
     * Resolve tally status bucket for an existing/system member.
     */
    private function getExistingTallyStatusBucket($enrollmentStatus, $status): string
    {
        if ($this->isExistingApprovedMember($enrollmentStatus)) {
            return 'ACTIVE_APPROVED';
        }

        if ($this->isResignedMember($enrollmentStatus, $status)) {
            return 'RESIGNED';
        }

        if ($this->isExistingNotEnrolledMember($enrollmentStatus, $status)) {
            return 'NOT_ENROLLED';
        }

        return 'OTHER';
    }

    /**
     * Resolve tally status bucket for a looker/temp member.
     */
    private function getTempTallyStatusBucket(array $tempRow, string $todayDate): string
    {
        if ($this->isTempApprovedMember($tempRow)) {
            return 'ACTIVE_APPROVED';
        }

        if ($this->isTempRowResigned($tempRow, $todayDate)) {
            return 'RESIGNED';
        }

        if ($this->isTempNotEnrolledMember($tempRow)) {
            return 'NOT_ENROLLED';
        }

        return 'OTHER';
    }

    /**
     * Build a safe folder segment for Spaces paths.
     */
    private function sanitizeImportFolderSegment(?string $value, string $fallback): string
    {
        $segment = Str::slug((string) $value, '-');

        return $segment !== '' ? $segment : $fallback;
    }

    /**
     * Build the public URL for a Spaces object.
     */
    private function buildSpacesPublicUrl(string $fullPath): string
    {
        $endpoint = rtrim(config('filesystems.disks.spaces.endpoint'), '/');

        if (str_starts_with($endpoint, 'https://')) {
            return 'https://llibi-self-enrollment.' . substr($endpoint, 8) . '/' . ltrim($fullPath, '/');
        }

        return $endpoint . '/' . ltrim($fullPath, '/');
    }

    /**
     * Resolve company and provider details from an enrollment.
     */
    private function getEnrollmentImportContext(int $enrollmentId): array
    {
        $enrollment = Enrollment::find($enrollmentId);

        if (!$enrollment) {
            throw new \Exception('Enrollment not found: ' . $enrollmentId);
        }

        $company = Company::find($enrollment->company_id);
        $provider = InsuranceProvider::find($enrollment->insurance_provider_id);

        return [
            'company_code' => $company->company_code ?? null,
            'provider_title' => $provider->title ?? null,
        ];
    }

    /**
     * Resolve enrollment ID using company code and provider title.
     *
     * @throws \Exception
     */
    private function findEnrollmentByCompanyAndProvider(string $companyCode, string $providerTitle): int
    {
        $normalizedCompanyCode = strtoupper(trim($companyCode));
        $normalizedProviderTitle = strtoupper(trim($providerTitle));

        if ($normalizedCompanyCode === '' || $normalizedProviderTitle === '') {
            throw new \Exception('Company code and provider title are required.');
        }

        $company = Company::query()
            ->whereRaw('UPPER(TRIM(company_code)) = ?', [$normalizedCompanyCode])
            ->first();

        if (!$company) {
            throw new \Exception('Company not found for code: ' . $companyCode);
        }

        $provider = InsuranceProvider::query()
            ->whereRaw('UPPER(TRIM(title)) = ?', [$normalizedProviderTitle])
            ->first();

        if (!$provider) {
            throw new \Exception('Insurance provider not found for title: ' . $providerTitle);
        }

        $enrollment = Enrollment::query()
            ->where('company_id', $company->id)
            ->where('insurance_provider_id', $provider->id)
            ->first();

        if (!$enrollment) {
            throw new \Exception(
                'Enrollment not found for company code ' . $companyCode . ' and provider title ' . $providerTitle
            );
        }

        return (int) $enrollment->id;
    }

    /**
     * Resolve folder context for file uploads based on import payload.
     */
    private function resolveImportFileContext(?int $enrollmentId, array $enrollees): array
    {
        if ($enrollmentId) {
            return $this->getEnrollmentImportContext($enrollmentId);
        }

        $companyCodes = [];
        $providerTitles = [];

        foreach ($enrollees as $enrollee) {
            if (!is_array($enrollee)) {
                continue;
            }

            $companyCode = trim((string) ($enrollee['company_code'] ?? ''));
            $providerTitle = trim((string) ($enrollee['insurance_provider_title'] ?? ''));

            if ($companyCode !== '') {
                $companyCodes[] = strtoupper($companyCode);
            }

            if ($providerTitle !== '') {
                $providerTitles[] = strtoupper($providerTitle);
            }
        }

        $companyCodes = array_values(array_unique($companyCodes));
        $providerTitles = array_values(array_unique($providerTitles));

        return [
            'company_code' => count($companyCodes) === 1 ? $companyCodes[0] : 'MULTIPLE-COMPANIES',
            'provider_title' => count($providerTitles) === 1 ? $providerTitles[0] : 'MULTIPLE-PROVIDERS',
        ];
    }

    /**
     * Store the uploaded import file in Spaces.
     */
    private function storeImportSourceFile(Request $request, ?int $enrollmentId, array $enrollees): ?array
    {
        if (!$request->hasFile('file')) {
            return null;
        }

        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            throw new \Exception('Invalid import file upload.');
        }

        $context = $this->resolveImportFileContext($enrollmentId, $enrollees);
        $companySegment = $this->sanitizeImportFolderSegment($context['company_code'] ?? null, 'unknown-company');
        $providerSegment = $this->sanitizeImportFolderSegment($context['provider_title'] ?? null, 'unknown-provider');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = $this->sanitizeImportFolderSegment($baseName, 'import-file');
        $fileName = now()->format('Ymd_His') . '_' . Str::random(8) . '_' . $safeBaseName . '.' . $extension;
        $folder = "self-enrollment/{$companySegment}/{$providerSegment}/imports";
        $fullPath = $folder . '/' . $fileName;

        Storage::disk('spaces')->put($fullPath, file_get_contents($file), ['visibility' => 'public']);

        $fileMeta = [
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $fileName,
            'path' => $fullPath,
            'url' => $this->buildSpacesPublicUrl($fullPath),
            'mime_type' => $file->getClientMimeType(),
            'company_code' => $context['company_code'] ?? null,
            'provider_title' => $context['provider_title'] ?? null,
            'uploaded_at' => now()->toDateTimeString(),
        ];

        $this->importLog['source_file'] = $fileMeta;

        Log::info('Import source file uploaded to Spaces', $fileMeta);

        return $fileMeta;
    }

    public function import(Request $request): JsonResponse
    {
        // This endpoint can process large spreadsheets with many DB writes,
        // file uploads, and log records. Give it enough time to finish so the
        // browser doesn't see a timeout/network failure.
        set_time_limit(300);

        // Bulk imports can run long enough for MySQL to close an idle
        // connection ("MySQL server has gone away"). Bump the session
        // timeouts and make sure we have a live connection before starting
        // the transaction.
        $this->ensureStableDbConnection();

        DB::beginTransaction();

        try {
            $enrollees = $this->parseEnrolleesInput($request);
            $importColumns = $this->parseImportColumnsInput($request);
            $enrollmentId = $request->input('enrollment_id');

            if (!$enrollmentId) {
                throw new \Exception('Enrollment ID is required for import.');
            }

            $this->storeTempImportRowsForEnrollment((int) $enrollmentId, $enrollees, $importColumns);

            $sourceFile = $this->storeImportSourceFile($request, $enrollmentId ? (int) $enrollmentId : null, $enrollees);
            $this->resetResignedAuditCandidates();

            // Initialize import log
            $this->importLog['summary']['total_principals'] = count($enrollees);

            // Perform bulk date analysis before processing individual records
            $dateFields = [
                'birth_date',
                'employment_start_date',
                'employment_end_date',
            ];

            // Check if user specified a date format preference
            $forcedFormat = $request->input('date_format', 'mdy'); // 'dmy', 'mdy', or null for auto

            if ($forcedFormat && in_array($forcedFormat, ['dmy', 'mdy'])) {
                $dateAnalysis = ['recommended_format' => $forcedFormat];
                Log::info("Using forced date format", [
                    'total_enrollees' => count($enrollees),
                    'forced_date_format' => $forcedFormat
                ]);
            } else {
                $dateAnalysis = $this->analyzeBulkDates($enrollees, $dateFields);
                Log::info("Starting import with date format analysis", [
                    'total_enrollees' => count($enrollees),
                    'detected_date_format' => $dateAnalysis['recommended_format'],
                    'date_confidence' => $dateAnalysis['confidence']
                ]);
            }

            $principalMap = [];
            $dependentsByPrincipal = [];
            $lockedResignedEmployeeIds = [];
            $insuranceFields = (new HealthInsurance())->getFillable();

            // Separate principals and dependents
            foreach ($enrollees as $index => $enrolleeData) {
                $relationRaw = $enrolleeData['relation'] ?? '';
                $relation = strtoupper(trim($relationRaw));
                $employeeId = $enrolleeData['employee_id'] ?? null;

                // Handle employee_id with generated number format (employee_id-generated_number)
                $originalEmployeeId = $employeeId;
                if ($employeeId && strpos($employeeId, '-') !== false) {
                    $baseEmployeeId = explode('-', $employeeId)[0];

                    // Check if base employee_id exists as principal in this enrollment
                    $existingPrincipal = Enrollee::withTrashed()
                        ->where('employee_id', $baseEmployeeId)
                        ->where('enrollment_id', $enrollmentId)
                        ->first();

                    if ($existingPrincipal) {
                        // Use the base employee_id if principal exists
                        $employeeId = $baseEmployeeId;
                        $enrolleeData['employee_id'] = $baseEmployeeId;
                        Log::info("Found existing principal with base employee_id", [
                            'original_employee_id' => $originalEmployeeId,
                            'base_employee_id' => $baseEmployeeId,
                            'existing_principal_id' => $existingPrincipal->id
                        ]);
                    }
                    // If no existing principal found, keep the original employee_id with generated number
                }

                // If the frontend included leftover (unmapped) Excel columns as
                // delimited text, track which user imported them so the data
                // can be scoped/filtered per user. Only relevant for principal
                // rows; the Dependent model has no unmapped_columns column, so
                // mass-assignment will simply ignore it for dependent rows.
                if (!empty($enrolleeData['unmapped_columns'])) {
                    $enrolleeData['unmapped_columns_by'] = auth()->id();
                } else {
                    unset($enrolleeData['unmapped_columns'], $enrolleeData['unmapped_columns_by']);
                }

                // Separate health insurance fields from enrollee data
                $healthInsuranceData = [];

                foreach ($insuranceFields as $field) {
                    if (array_key_exists($field, $enrolleeData)) {
                        $healthInsuranceData[$field] = $enrolleeData[$field];
                        unset($enrolleeData[$field]);
                    }
                }

                // Process health insurance data
                $isPrincipal = ($relation === 'PRINCIPAL' || $relation === 'EMPLOYEE' || $relation === 'EMPLOYEES');

                $healthInsuranceData = $this->processHealthInsuranceData($healthInsuranceData, true);
                $isRenewal = (bool) ($healthInsuranceData['is_renewal'] ?? false);

                if (!isset($enrolleeData['enrollment_status'])) {
                    // Set enrollment status based on health insurance data
                    if (!empty($healthInsuranceData['certificate_number']) && !$isRenewal) {
                        $enrolleeData['enrollment_status'] = 'APPROVED';
                    }

                    if (!empty($healthInsuranceData['certificate_number']) && $isRenewal) {
                        $enrolleeData['enrollment_status'] = 'FOR-RENEWAL';
                    }

                    if ($healthInsuranceData['is_skipping'] || !empty($healthInsuranceData['reason_for_skipping'])) {
                        $enrolleeData['enrollment_status'] = 'SKIPPED';
                    }
                }

                if ($isPrincipal) {

                    $existingPrincipalForSkipCheck = Enrollee::withTrashed()
                        ->where('employee_id', $employeeId)
                        ->where('enrollment_id', $enrollmentId)
                        ->first();

                    if ($this->isLockedResignedPrincipal($existingPrincipalForSkipCheck)) {
                        $principalMap[$employeeId] = $existingPrincipalForSkipCheck;
                        $lockedResignedEmployeeIds[$employeeId] = true;

                        $this->logPrincipal(
                            $employeeId,
                            'skipped',
                            $enrolleeData,
                            [],
                            []
                        );

                        Log::info('Skipping principal update because member is resigned/locked', [
                            'employee_id' => $employeeId,
                            'enrollment_id' => $enrollmentId,
                            'principal_id' => $existingPrincipalForSkipCheck->id,
                        ]);

                        continue;
                    }

                    $principal = $this->createOrUpdatePrincipalWithSoftDelete($enrolleeData, $enrollmentId, $employeeId, $dateAnalysis['recommended_format']);

                    // Verify principal was created/updated successfully
                    if (!$principal || !$principal->id) {
                        throw new \Exception("Failed to create or update principal for employee {$employeeId}");
                    }

                    $principalMap[$employeeId] = $principal;

                    // Persist normalized unmapped column name/value pairs
                    if (!empty($enrolleeData['unmapped_columns'])) {
                        $this->syncUnmappedColumnValues($principal, $enrolleeData['unmapped_columns']);
                    }

                    // Log principal import
                    $this->logPrincipal(
                        $employeeId,
                        $this->importLog['summary']['principals_created'] > 0 && in_array($employeeId, array_keys($principalMap)) ? 'updated' : 'created',
                        $enrolleeData,
                        [],
                        $healthInsuranceData
                    );

                    // Attach health insurance if data exists
                    if (!empty($healthInsuranceData)) {
                        // Set coverage_end_date from employment_end_date if employee is INACTIVE
                        if (isset($enrolleeData['employment_end_date'])) {
                            $healthInsuranceData['coverage_end_date'] = $enrolleeData['employment_end_date'];
                        }

                        $this->attachHealthInsurance(new Request([
                            'enrollee_id' => $principal->id,
                            'insurance' => $healthInsuranceData
                        ]), $dateAnalysis['recommended_format']);
                    }

                    $this->queueResignedImportAuditCandidate(
                        $principal,
                        $enrolleeData,
                        $healthInsuranceData,
                        (int) $enrollmentId
                    );
                } else {

                    if ($relation === 'DEPENDENTS' || $relation === 'DEPENDENT') {
                        unset($enrolleeData['relation']);
                    }

                    if (!isset($dependentsByPrincipal[$employeeId])) {
                        $dependentsByPrincipal[$employeeId] = [];
                    }

                    $dependentsByPrincipal[$employeeId][] = [
                        'enrollee_data' => $enrolleeData,
                        'health_insurance_data' => $healthInsuranceData
                    ];
                }
            }

            // Update total dependents count
            $this->importLog['summary']['total_dependents'] = array_sum(array_map('count', $dependentsByPrincipal));

            // Process dependents
            foreach ($dependentsByPrincipal as $employeeId => $dependents) {
                if (isset($lockedResignedEmployeeIds[$employeeId])) {
                    Log::info('Skipping dependents for resigned/locked principal', [
                        'employee_id' => $employeeId,
                        'enrollment_id' => $enrollmentId,
                    ]);
                    continue;
                }

                $principal = $principalMap[$employeeId] ?? null;

                // If principal was not in this import batch, try to find them in the database
                if (!$principal) {
                    $principal = Enrollee::withTrashed()
                        ->where('employee_id', $employeeId)
                        ->where('enrollment_id', $enrollmentId)
                        ->first();

                    if ($principal) {
                        Log::info('Principal not in import batch but found in database', [
                            'employee_id' => $employeeId,
                            'principal_id' => $principal->id
                        ]);
                    } else {
                        Log::warning('Principal not found in import batch or database, skipping dependents', [
                            'employee_id' => $employeeId
                        ]);
                        continue;
                    }
                } else {
                    // Double-check that the principal still exists in the database
                    $principalExists = Enrollee::withTrashed()->find($principal->id);
                    if (!$principalExists) {
                        Log::error('Principal exists in map but not in database', [
                            'employee_id' => $employeeId,
                            'principal_id' => $principal->id,
                            'principal_object' => $principal->toArray()
                        ]);
                        continue;
                    }
                }

                if ($this->isLockedResignedPrincipal($principal)) {
                    Log::info('Skipping dependents because principal is resigned/locked', [
                        'employee_id' => $employeeId,
                        'enrollment_id' => $enrollmentId,
                        'principal_id' => $principal->id,
                    ]);
                    continue;
                }

                $dependentsData = [];
                foreach ($dependents as $dependent) {
                    $enrolleeData = $dependent['enrollee_data'];
                    $healthInsuranceData = $dependent['health_insurance_data'];

                    $enrolleeData['principal_id'] = $principal->id;
                    $enrolleeData['principal_employee_id'] = $principal->employee_id;

                    $dependentsData[] = $enrolleeData;
                }

                // Use attachDependents method
                $response = $this->attachDependents(new Request([
                    'enrollee_id' => $principal->id,
                    'dependents' => $dependentsData
                ]), $dateAnalysis['recommended_format']);

                // Attach health insurance for each dependent
                if ($response->getStatusCode() === 200) {
                    $responseData = json_decode($response->getContent(), true);
                    $createdDependents = $responseData['dependents'] ?? [];

                    foreach ($dependents as $index => $dependent) {
                        $healthInsuranceData = $dependent['health_insurance_data'];
                        if (!empty($healthInsuranceData) && isset($createdDependents[$index])) {
                            // Log dependent import
                            $this->logDependent(
                                $principal->id,
                                $employeeId,
                                'created',
                                $dependent['enrollee_data'],
                                [],
                                $healthInsuranceData
                            );

                            $this->attachHealthInsurance(new Request([
                                'dependent_id' => $createdDependents[$index]['id'],
                                'insurance' => $healthInsuranceData
                            ]), $dateAnalysis['recommended_format']);
                        }
                    }
                } else {
                    Log::error('Failed to attach dependents', [
                        'employee_id' => $employeeId,
                        'response_code' => $response->getStatusCode(),
                        'response_body' => $response->getContent()
                    ]);
                }
            }

            Log::info('About to commit regular import transaction', [
                'principals_processed' => count($principalMap),
                'transaction_level_before_commit' => DB::transactionLevel(),
            ]);

            $transactionLevel = DB::transactionLevel();

            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::commit();

                Log::info('Committed regular import transaction level', [
                    'level' => $i + 1,
                    'remaining_levels' => DB::transactionLevel(),
                ]);
            }

            Log::info('Regular import transaction committed successfully', [
                'principals_processed' => count($principalMap),
                'final_transaction_level' => DB::transactionLevel(),
            ]);

            // Save import log to database
            $importLog = $this->saveImportLog(
                $enrollmentId,
                $dateAnalysis['recommended_format'],
                $dateAnalysis['confidence'] ?? '0%',
                'success'
            );

            $this->persistImportedEnrollmentFileRecord($importLog->id, (int) $enrollmentId, 'regular');
            $this->persistResignedImportAudits($importLog->id);

            return response()->json([
                'message' => 'Import successful',
                'import_log_id' => $importLog->id,
                'summary' => $this->importLog['summary'],
                'source_file' => $sourceFile,
            ], 200);
        } catch (\Exception $e) {

            // Save import log with error status
            try {
                $this->saveImportLog(
                    $enrollmentId ?? 0,
                    'unknown',
                    '0%',
                    'failed',
                    $e->getMessage()
                );
            } catch (\Exception $logError) {
                Log::error('Failed to save error log', ['error' => $logError->getMessage()]);
            }

            // Rollback all transaction levels. If the connection was already
            // dropped by MySQL ("server has gone away"), the rollback itself
            // will throw — guard against that so we still return a clean
            // JSON response instead of crashing.
            $this->safeRollback();

            return response()->json([
                'message' => $this->isConnectionLostError($e) ? $this->connectionLostMessage() : 'Import failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import enrollees with company and provider info
     */
    public function importWithCompanyAndProvider(Request $request): JsonResponse
    {
        // Bulk imports can process many principals + dependents, each with
        // several DB queries and log writes. Give this endpoint more time
        // than the default 30s so large imports don't hit a fatal timeout
        // (which also prevents CORS headers from being sent back).
        set_time_limit(300);

        // Bump the session timeouts and ensure the connection is alive
        // before starting a potentially long-running transaction, to
        // reduce the chance of a "MySQL server has gone away" error.
        $this->ensureStableDbConnection();

        DB::beginTransaction();

        try {
            $enrollees = $this->parseEnrolleesInput($request);
            $importColumns = $this->parseImportColumnsInput($request);

            $sourceFile = $this->storeImportSourceFile($request, null, $enrollees);
            $this->resetResignedAuditCandidates();

            // Initialize import log summary
            $this->importLog['summary']['total_principals'] = count($enrollees);

            // Perform bulk date analysis before processing individual records
            $dateFields = [
                'birth_date',
                'employment_start_date',
                'employment_end_date',
            ];

            // Check if user specified a date format preference
            $forcedFormat = $request->input('date_format', 'dmy'); // 'dmy', 'mdy', or null for auto

            if ($forcedFormat && in_array($forcedFormat, ['dmy', 'mdy'])) {
                $dateAnalysis = ['recommended_format' => $forcedFormat];
                Log::info('Using forced date format for import with company and provider', [
                    'enrollees_count' => count($enrollees),
                    'forced_date_format' => $forcedFormat,
                    'first_enrollee' => $enrollees[0] ?? null
                ]);
            } else {
                $dateAnalysis = $this->analyzeBulkDates($enrollees, $dateFields);
                Log::info('Import request data with date format analysis:', [
                    'enrollees_count' => count($enrollees),
                    'first_enrollee' => $enrollees[0] ?? null,
                    'detected_date_format' => $dateAnalysis['recommended_format'],
                    'date_confidence' => $dateAnalysis['confidence']
                ]);
            }

            $principalMap = [];
            $affectedEnrollmentIds = [];
            $tempRowsByEnrollment = [];
            $insuranceFields = (new HealthInsurance())->getFillable();
            $uploadDate = now();
            $uploadMonthYear = $uploadDate->format('Y-m');
            $uploadDateString = $uploadDate->toDateString();

            foreach ($enrollees as $enrolleeData) {
                $forceCoverageEndDateNow = false;
                $employeeId = $enrolleeData['employee_id'] ?? null;
                $enrolleeCompanyCode = $enrolleeData['company_code'] ?? null;
                $enrolleeInsuranceProviderTitle = $enrolleeData['insurance_provider_title'] ?? null;

                // Validate required fields
                if (!$enrolleeCompanyCode || !$enrolleeInsuranceProviderTitle) {
                    Log::warning('Missing company/provider info for enrollee', [
                        'employee_id' => $employeeId,
                        'company_code' => $enrolleeCompanyCode,
                        'insurance_provider_title' => $enrolleeInsuranceProviderTitle
                    ]);
                    return response()->json(['message' => 'Missing company_code or insurance_provider_title for employee: ' . $employeeId], 400);
                }

                // Find enrollment by company and provider
                try {
                    $currentEnrollmentId = $this->findEnrollmentByCompanyAndProvider($enrolleeCompanyCode, $enrolleeInsuranceProviderTitle);
                } catch (\Exception $e) {
                    return response()->json(['message' => $e->getMessage()], 404);
                }

                if (!in_array($currentEnrollmentId, $affectedEnrollmentIds, true)) {
                    $affectedEnrollmentIds[] = $currentEnrollmentId;
                }

                if (!isset($tempRowsByEnrollment[$currentEnrollmentId])) {
                    $tempRowsByEnrollment[$currentEnrollmentId] = [];
                }

                $tempRowsByEnrollment[$currentEnrollmentId][] = $enrolleeData;

                // Fast skip: existing resigned principals are immutable.
                $existingPrincipalForSkipCheck = Enrollee::withTrashed()
                    ->where('employee_id', $employeeId)
                    ->where('enrollment_id', $currentEnrollmentId)
                    ->first(['id', 'employee_id', 'enrollment_id', 'employment_end_date', 'enrollment_status', 'status', 'unmapped_columns']);

                if ($this->isLockedResignedPrincipal($existingPrincipalForSkipCheck)) {
                    $principalMap[$employeeId] = $existingPrincipalForSkipCheck;

                    $this->logPrincipal(
                        $employeeId,
                        'skipped',
                        $enrolleeData,
                        [],
                        []
                    );

                    Log::info('Skipping principal entirely; member is resigned/locked', [
                        'employee_id' => $employeeId,
                        'enrollment_id' => $currentEnrollmentId,
                        'existing_employment_end_date' => $existingPrincipalForSkipCheck->employment_end_date,
                    ]);

                    continue;
                }

                // Remove company_code and insurance_provider_title from enrollee data
                unset($enrolleeData['company_code'], $enrolleeData['insurance_provider_title']);

                // Multi-provider special rule:
                // If mapped employment_end_date month/year is earlier than the
                // upload month/year, move that mapped date to back_date, then
                // set employee_end_date and coverage_end_date to upload date (today).
                if (!empty($enrolleeData['employment_end_date'])) {
                    $mappedEmploymentEndDate = $this->sanitizeDateWithFormat(
                        $enrolleeData['employment_end_date'],
                        $dateAnalysis['recommended_format']
                    );

                    if (!empty($mappedEmploymentEndDate)) {
                        $mappedEndMonthYear = date('Y-m', strtotime($mappedEmploymentEndDate));
                        $isPriorMonthYear = $mappedEndMonthYear < $uploadMonthYear;
                        $isSameMonthYearPastDay = $mappedEndMonthYear === $uploadMonthYear
                            && $mappedEmploymentEndDate < $uploadDateString;

                        if ($isPriorMonthYear || $isSameMonthYearPastDay) {
                            $enrolleeData['back_date'] = $mappedEmploymentEndDate;
                            $enrolleeData['employment_end_date'] = $uploadDateString;
                            $forceCoverageEndDateNow = true;

                            Log::info('Multi-provider end-date conversion applied', [
                                'employee_id' => $employeeId,
                                'enrollment_id' => $currentEnrollmentId,
                                'mapped_employment_end_date' => $mappedEmploymentEndDate,
                                'back_date_set_to' => $mappedEmploymentEndDate,
                                'employment_end_date_set_to' => $uploadDateString,
                                'coverage_end_date_set_to' => $uploadDateString,
                                'upload_month_year' => $uploadMonthYear,
                                'mapped_month_year' => $mappedEndMonthYear,
                            ]);
                        }
                    }
                }

                // If the frontend included leftover (unmapped) Excel columns as
                // delimited text, track which user imported them so the data
                // can be scoped/filtered per user.
                if (!empty($enrolleeData['unmapped_columns'])) {
                    $enrolleeData['unmapped_columns_by'] = auth()->id();
                } else {
                    unset($enrolleeData['unmapped_columns'], $enrolleeData['unmapped_columns_by']);
                }

                // Separate health insurance fields from enrollee data
                $healthInsuranceData = [];

                foreach ($insuranceFields as $field) {
                    if (array_key_exists($field, $enrolleeData)) {
                        $healthInsuranceData[$field] = $enrolleeData[$field];
                        unset($enrolleeData[$field]);
                    }
                }

                // Handle employment end date for health insurance
                if ($forceCoverageEndDateNow) {
                    $healthInsuranceData['coverage_end_date'] = $uploadDateString;
                } elseif (isset($enrolleeData['employment_end_date']) && !empty($enrolleeData['employment_end_date'])) {
                    $healthInsuranceData['coverage_end_date'] = $enrolleeData['employment_end_date'];
                }

                // Process health insurance data
                $healthInsuranceData = $this->processHealthInsuranceData($healthInsuranceData, false);

                // Determine whether this principal already exists (for logging purposes)
                $principalExistedBefore = Enrollee::withTrashed()
                    ->where('employee_id', $employeeId)
                    ->where('enrollment_id', $currentEnrollmentId)
                    ->exists();

                // Create or update principal with soft delete handling
                $principal = $this->createOrUpdatePrincipalWithSoftDelete($enrolleeData, $currentEnrollmentId, $employeeId, $dateAnalysis['recommended_format']);
                $principalMap[$employeeId] = $principal;

                // Persist normalized unmapped column name/value pairs
                if (!empty($enrolleeData['unmapped_columns'])) {
                    $this->syncUnmappedColumnValues($principal, $enrolleeData['unmapped_columns']);
                }

                // Log principal import
                $this->logPrincipal(
                    $employeeId,
                    $principalExistedBefore ? 'updated' : 'created',
                    $enrolleeData,
                    [],
                    $healthInsuranceData
                );

                // Attach health insurance if data exists
                if (!empty($healthInsuranceData)) {
                    $this->attachHealthInsurance(new Request([
                        'enrollee_id' => $principal->id,
                        'insurance' => $healthInsuranceData
                    ]), $dateAnalysis['recommended_format']);
                }

                $this->queueResignedImportAuditCandidate(
                    $principal,
                    $enrolleeData,
                    $healthInsuranceData,
                    (int) $currentEnrollmentId
                );
            }

            // Record affected enrollments for reference in the log details
            $this->importLog['affected_enrollment_ids'] = $affectedEnrollmentIds;

            $this->storeTempImportRowsByEnrollment($tempRowsByEnrollment, $importColumns);

            Log::info('About to commit transaction', [
                'principals_processed' => count($principalMap),
                'transaction_level_before_commit' => DB::transactionLevel()
            ]);

            // Commit all transaction levels to ensure data is actually saved
            $transactionLevel = DB::transactionLevel();

            for ($i = 0; $i < $transactionLevel; $i++) {
                DB::commit();

                Log::info('Committed transaction level', [
                    'level' => $i + 1,
                    'remaining_levels' => DB::transactionLevel()
                ]);
            }

            Log::info('All transactions committed successfully', [
                'principals_processed' => count($principalMap),
                'final_transaction_level' => DB::transactionLevel()
            ]);

            // Save import log to database (multi-provider import, no single enrollment_id)
            $importLog = $this->saveImportLog(
                null,
                $dateAnalysis['recommended_format'],
                $dateAnalysis['confidence'] ?? '0%',
                'success'
            );

            $this->persistResignedImportAudits($importLog->id);
            $this->persistImportedEnrollmentFileRecord($importLog->id, null, 'multi-provider');

            return response()->json([
                'message' => 'Import successful',
                'import_log_id' => $importLog->id,
                'summary' => $this->importLog['summary'],
                'source_file' => $sourceFile,
            ], 200);
        } catch (\Exception $e) {

            Log::error('Exception occurred during importWithCompanyAndProvider', [
                'error' => $e->getMessage(),
                'transaction_level_before_rollback' => DB::transactionLevel()
            ]);

            // Save import log with error status
            try {
                $this->saveImportLog(
                    null,
                    'unknown',
                    '0%',
                    'failed',
                    $e->getMessage()
                );
            } catch (\Exception $logError) {
                Log::error('Failed to save error log', ['error' => $logError->getMessage()]);
            }

            // Rollback all transaction levels. If the connection was already
            // dropped by MySQL ("server has gone away"), the rollback itself
            // will throw — guard against that so we still return a clean
            // JSON response instead of crashing.
            $this->safeRollback();

            return response()->json([
                'message' => $this->isConnectionLostError($e) ? $this->connectionLostMessage() : 'Import failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update principal with soft delete handling.
     */
    private function createOrUpdatePrincipalWithSoftDelete(array $enrolleeData, int $enrollmentId, string $employeeId, string $dateFormat = 'auto'): Enrollee
    {
        $enrolleeData['enrollment_id'] = $enrollmentId;
        $originalEmploymentEndDate = $enrolleeData['employment_end_date'] ?? null;

        $enrolleeDateFields = ['birth_date', 'employment_start_date', 'employment_end_date'];
        foreach ($enrolleeDateFields as $dateField) {
            if (!array_key_exists($dateField, $enrolleeData)) {
                continue;
            }

            $rawDate = $enrolleeData[$dateField];
            if ($rawDate === null || trim((string) $rawDate) === '') {
                $enrolleeData[$dateField] = null;
                continue;
            }

            $enrolleeData[$dateField] = $this->sanitizeDateWithFormat($rawDate, $dateFormat);
            Log::info('Sanitized enrollee date field', [
                'field' => $dateField,
                'original' => $rawDate,
                'sanitized' => $enrolleeData[$dateField],
                'format_used' => $dateFormat,
            ]);
        }

        $unmappedColumnsOriginal = $enrolleeData['unmapped_columns'] ?? null;
        unset($enrolleeData['unmapped_columns']);

        $enrolleeData = $this->uppercaseStrings($enrolleeData);

        if ($unmappedColumnsOriginal !== null) {
            $enrolleeData['unmapped_columns'] = $unmappedColumnsOriginal;
        }

        $enrolleeData['status'] = 'ACTIVE';
        $shouldSoftDelete = false;

        $principalQuery = Enrollee::with(['healthInsurance'])
            ->withTrashed()
            ->where('employee_id', $employeeId)
            ->where('enrollment_id', $enrollmentId);

        $existingPrincipal = $principalQuery->first();

        $existingHadEndDate = $existingPrincipal && !empty($existingPrincipal->employment_end_date);
        $existingHasBackDate = $existingPrincipal && !empty($existingPrincipal->back_date);

        if ($existingHasBackDate) {
            unset($enrolleeData['back_date']);
        }

        $newEndDateProvided = (isset($enrolleeData['employment_end_date']) && !empty($enrolleeData['employment_end_date']))
            || ($originalEmploymentEndDate && !empty(trim($originalEmploymentEndDate)));

        if ($existingHadEndDate) {
            unset($enrolleeData['employment_end_date']);

            Log::info('Skipping employment_end_date update and back_date; principal already has an employment_end_date', [
                'employee_id' => $employeeId,
                'existing_employment_end_date' => $existingPrincipal->employment_end_date,
            ]);
        } elseif ($newEndDateProvided) {
            if (!empty($enrolleeData['employment_end_date'])) {
                $endDateMonthYear = date('Y-m', strtotime($enrolleeData['employment_end_date']));
                $currentMonthYear = date('Y-m');

                if ($endDateMonthYear < $currentMonthYear && !$existingHasBackDate) {
                    $enrolleeData['back_date'] = now();
                }
            }

            if (($enrolleeData['employment_end_date'] ?? null) <= date('Y-m-d')) {
                $enrolleeData['status'] = 'INACTIVE';
                $enrolleeData['enrollment_status'] = 'RESIGNED';
            }
        }

        $isRenewal = $existingPrincipal && $existingPrincipal->healthInsurance
            ? $existingPrincipal->healthInsurance->is_renewal
            : false;

        if ($existingPrincipal) {
            $hasChanges = false;
            $changes = [];

            foreach ($enrolleeData as $key => $value) {
                if (in_array($key, ['created_at', 'updated_at', 'coverage_end_date'])) {
                    continue;
                }

                $currentValue = $existingPrincipal->getAttribute($key);
                $normalizedCurrentValue = $this->normalizeValue($currentValue);
                $normalizedNewValue = $this->normalizeValue($value);

                if ($normalizedCurrentValue !== $normalizedNewValue) {
                    $hasChanges = true;
                    $changes[$key] = [
                        'old' => $currentValue,
                        'new' => $value,
                        'old_normalized' => $normalizedCurrentValue,
                        'new_normalized' => $normalizedNewValue,
                        'old_type' => gettype($currentValue),
                        'new_type' => gettype($value),
                    ];
                }
            }

            if ($hasChanges) {
                Log::info('Updating existing principal with changes', [
                    'employee_id' => $employeeId,
                    'changes' => $changes,
                    'is_renewal' => $isRenewal,
                ]);

                $existingPrincipal->update($enrolleeData);
            } else {
                Log::info('No principal changes detected, skipping update', [
                    'employee_id' => $employeeId,
                    'is_renewal' => $isRenewal,
                ]);
            }

            if ($shouldSoftDelete) {
                $currentUserId = auth()->check() ? auth()->user()->id : 1;
                $existingPrincipal->update(['deleted_by' => $currentUserId]);
                $existingPrincipal->delete();
            }

            return $existingPrincipal;
        }

        if (!isset($enrolleeData['employee_id']) || empty($enrolleeData['employee_id'])) {
            $enrolleeData['employee_id'] = $employeeId;
        }

        try {
            $principal = Enrollee::create($enrolleeData);

            if ($shouldSoftDelete) {
                $currentUserId = auth()->check() ? auth()->user()->id : 1;
                $principal->update(['deleted_by' => $currentUserId]);
                $principal->delete();
            }
        } catch (\Exception $e) {
            Log::error('Failed to create principal', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
                'sql_error' => $e->getPrevious() ? $e->getPrevious()->getMessage() : 'N/A',
                'enrollee_data' => $enrolleeData,
            ]);
            throw $e;
        }

        return $principal;
    }

    /**
     * Attach/update dependents for a principal.
     */
    public function attachDependents(Request $request, string $dateFormat = 'auto'): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrolleeId = $request->input('enrollee_id');
            $dependentsData = $request->input('dependents', []);

            if (empty($enrolleeId)) {
                return response()->json(['message' => 'Enrollee ID is required'], 400);
            }

            if (empty($dependentsData)) {
                return response()->json(['message' => 'No dependents data provided'], 400);
            }

            $enrollee = Enrollee::withTrashed()->find($enrolleeId);
            if (!$enrollee) {
                return response()->json(['message' => 'Enrollee not found with id: ' . $enrolleeId], 404);
            }

            $dependentFields = (new Dependent())->getFillable();
            $createdDependents = [];
            $hasChanges = false;

            foreach ($dependentsData as $dependentData) {
                $filtered = array_intersect_key($dependentData, array_flip($dependentFields));

                if (isset($filtered['birth_date']) && !empty($filtered['birth_date'])) {
                    $filtered['birth_date'] = $this->sanitizeDateWithFormat($filtered['birth_date'], $dateFormat);
                }

                $filtered = $this->uppercaseStrings($filtered);
                $filtered['principal_id'] = $enrolleeId;

                $dependentQuery = Dependent::withTrashed()->where('principal_id', $enrolleeId);
                if (isset($filtered['birth_date']) && !empty($filtered['birth_date'])) {
                    $dependentQuery = $dependentQuery->where('birth_date', $filtered['birth_date']);
                }

                $existingDependent = $dependentQuery->first();

                if ($existingDependent) {
                    $dependentHasChanges = false;

                    foreach ($filtered as $key => $value) {
                        if (in_array($key, ['created_at', 'updated_at'])) {
                            continue;
                        }

                        $currentValue = $existingDependent->getAttribute($key);
                        $normalizedCurrentValue = $this->normalizeValue($currentValue);
                        $normalizedNewValue = $this->normalizeValue($value);

                        if ($normalizedCurrentValue !== $normalizedNewValue) {
                            $dependentHasChanges = true;
                            break;
                        }
                    }

                    if ($dependentHasChanges) {
                        $existingDependent->update($filtered);
                        $hasChanges = true;
                    }

                    $dependent = $existingDependent;
                } else {
                    $dependent = Dependent::create($filtered);
                    $hasChanges = true;
                }

                $createdDependents[] = $dependent;
            }

            $enrollee->touch();

            DB::commit();
            return response()->json([
                'message' => 'Dependents attached successfully',
                'dependents' => $createdDependents,
                'had_changes' => $hasChanges,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to attach dependents', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Process health insurance data and normalize boolean-like fields.
     */
    private function processHealthInsuranceData(array $healthInsuranceData, bool $withRenewalChecking = false): array
    {
        $toBoolInt = static function ($value): int {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }

            if (is_numeric($value)) {
                return ((int) $value) > 0 ? 1 : 0;
            }

            $normalized = strtoupper(trim((string) $value));
            return in_array($normalized, ['YES', 'TRUE', '1', 'Y'], true) ? 1 : 0;
        };

        $healthInsuranceData['is_company_paid'] = isset($healthInsuranceData['is_company_paid'])
            ? $toBoolInt($healthInsuranceData['is_company_paid'])
            : 0;

        if ($withRenewalChecking && isset($healthInsuranceData['is_renewal'])) {
            $healthInsuranceData['is_renewal'] = $toBoolInt($healthInsuranceData['is_renewal']);
        }

        if (isset($healthInsuranceData['is_skipping'])) {
            $healthInsuranceData['is_skipping'] = $toBoolInt($healthInsuranceData['is_skipping']);
        } else {
            $healthInsuranceData['is_skipping'] = !empty($healthInsuranceData['reason_for_skipping']) ? 1 : 0;
        }

        return $healthInsuranceData;
    }

    /**
     * Attach/update health insurance record for principal or dependent.
     */
    public function attachHealthInsurance(Request $request, string $dateFormat = 'auto'): JsonResponse
    {
        DB::beginTransaction();

        try {
            $enrolleeId = $request->input('enrollee_id');
            $dependentId = $request->input('dependent_id');
            $insuranceData = $request->input('insurance', []);

            if (!$enrolleeId && !$dependentId) {
                return response()->json(['message' => 'Either enrollee_id or dependent_id must be provided'], 400);
            }

            if (empty($insuranceData)) {
                return response()->json(['message' => 'No insurance data provided'], 400);
            }

            $insuranceFields = (new HealthInsurance())->getFillable();
            $filtered = array_intersect_key($insuranceData, array_flip($insuranceFields));

            $dateFields = [
                'coverage_start_date',
                'coverage_end_date',
                'certificate_date_issued',
                'kyc_datestamp',
                'card_delivery_date',
            ];

            foreach ($dateFields as $dateField) {
                if (isset($filtered[$dateField]) && !empty($filtered[$dateField])) {
                    $filtered[$dateField] = $this->sanitizeDateWithFormat($filtered[$dateField], $dateFormat);
                }
            }

            $filtered = $this->uppercaseStrings($filtered);

            $dependent = null;
            $enrollee = null;

            if ($enrolleeId) {
                $enrollee = Enrollee::find($enrolleeId);
                if (!$enrollee) {
                    return response()->json(['message' => 'Enrollee not found with id: ' . $enrolleeId], 404);
                }

                $filtered['principal_id'] = $enrolleeId;
            } else {
                $dependent = Dependent::find($dependentId);
                if (!$dependent) {
                    return response()->json(['message' => 'Dependent not found with id: ' . $dependentId], 404);
                }

                $filtered['dependent_id'] = $dependentId;

                if (!empty($filtered['certificate_number'])) {
                    if (empty($filtered['coverage_start_date'])) {
                        $filtered['coverage_start_date'] = date('Y-m-d', strtotime('first day of next month'));
                    }
                    if (empty($filtered['certificate_date_issued'])) {
                        $filtered['certificate_date_issued'] = $filtered['coverage_start_date'];
                    }

                    $dependent->update(['enrollment_status' => 'APPROVED']);
                }
            }

            $insuranceQuery = HealthInsurance::query();
            if (isset($filtered['principal_id'])) {
                $insuranceQuery->where('principal_id', $filtered['principal_id']);
            } elseif (isset($filtered['dependent_id'])) {
                $insuranceQuery->where('dependent_id', $filtered['dependent_id']);
            }

            $existingInsurance = $insuranceQuery->first();
            $hasInsuranceChanges = false;

            if ($existingInsurance) {
                foreach ($filtered as $key => $value) {
                    if (in_array($key, ['created_at', 'updated_at'])) {
                        continue;
                    }

                    $currentValue = $existingInsurance->getAttribute($key);
                    if ($this->normalizeValue($currentValue) !== $this->normalizeValue($value)) {
                        $hasInsuranceChanges = true;
                        break;
                    }
                }

                if ($hasInsuranceChanges) {
                    $existingInsurance->update($filtered);
                }

                $insurance = $existingInsurance;
            } else {
                $insurance = HealthInsurance::create($filtered);
                $hasInsuranceChanges = true;
            }

            if ($hasInsuranceChanges) {
                if ($enrollee) {
                    $enrollee->touch();
                } elseif ($dependent && $dependent->principal_id) {
                    $principal = Enrollee::find($dependent->principal_id);
                    if ($principal) {
                        $principal->touch();
                    }
                }
            }

            DB::commit();
            return response()->json(['message' => 'Health insurance attached successfully', 'insurance' => $insurance], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to attach health insurance', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Return uploaded source files that can be reused in billing/import UI.
     */
    public function getImportedFiles(Request $request): JsonResponse
    {
        try {
            $query = ImportedEnrollmentFile::query()
                ->select([
                    'id',
                    'enrollment_id',
                    'import_log_id',
                    'source_type',
                    'company_code',
                    'provider_title',
                    'original_name',
                    'stored_name',
                    'file_path',
                    'file_url',
                    'mime_type',
                    'uploaded_at',
                    'created_at',
                ])
                ->orderByDesc('uploaded_at')
                ->orderByDesc('id');

            $enrollmentId = $request->filled('enrollment_id') ? (int) $request->input('enrollment_id') : null;
            $companyCode = $request->filled('company_code') ? trim((string) $request->input('company_code')) : null;
            $providerTitle = $request->filled('provider_title') ? trim((string) $request->input('provider_title')) : null;

            if ($enrollmentId || $companyCode || $providerTitle) {
                $query->where(function ($groupQuery) use ($enrollmentId, $companyCode, $providerTitle) {
                    if ($enrollmentId) {
                        $groupQuery->where('enrollment_id', $enrollmentId);
                    }

                    if ($companyCode || $providerTitle) {
                        $groupQuery->orWhere(function ($matchQuery) use ($companyCode, $providerTitle) {
                            $matchQuery->whereNull('enrollment_id');

                            if ($companyCode) {
                                $matchQuery->whereRaw('LOWER(TRIM(company_code)) = ?', [strtolower($companyCode)]);
                            }

                            if ($providerTitle) {
                                $matchQuery->whereRaw('LOWER(TRIM(provider_title)) = ?', [strtolower($providerTitle)]);
                            }
                        });
                    }
                });
            }

            if ($request->filled('source_type')) {
                $query->where('source_type', trim((string) $request->input('source_type')));
            }

            $files = $query->get()->map(function ($file) {
                return [
                    'id' => (int) ($file->id ?? 0),
                    'enrollment_id' => $file->enrollment_id ? (int) $file->enrollment_id : null,
                    'import_log_id' => $file->import_log_id ? (int) $file->import_log_id : null,
                    'source_type' => $file->source_type,
                    'company_code' => $file->company_code,
                    'provider_title' => $file->provider_title,
                    'original_name' => $file->original_name,
                    'stored_name' => $file->stored_name,
                    'file_path' => $file->file_path,
                    'file_url' => $file->file_url,
                    'mime_type' => $file->mime_type,
                    'uploaded_at' => $file->uploaded_at?->toDateTimeString(),
                    'created_at' => $file->created_at?->toDateTimeString(),
                ];
            })->all();

            return response()->json([
                'data' => $files,
                'count' => count($files),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to get imported files', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch imported files.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Return import logs for the billing/import history UI.
     */
    public function getImportLogs(Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->input('limit', 100);
            $limit = max(1, min($limit, 500));

            $query = ImportLog::query()
                ->select([
                    'id',
                    'enrollment_id',
                    'import_date',
                    'total_principals',
                    'total_dependents',
                    'principals_created',
                    'principals_updated',
                    'dependents_created',
                    'dependents_updated',
                    'status',
                    'error_message',
                    'date_format_detected',
                    'date_format_confidence',
                ])
                ->orderByDesc('import_date');

            if ($request->filled('enrollment_id')) {
                $query->where('enrollment_id', (int) $request->input('enrollment_id'));
            }

            if ($request->boolean('multi_provider')) {
                $query->whereNull('enrollment_id');
            }

            $logs = $query->limit($limit)->get();

            $sourceFilesByImportLogId = [];
            if ($logs->isNotEmpty()) {
                $sourceFilesByImportLogId = ImportedEnrollmentFile::query()
                    ->select([
                        'import_log_id',
                        'company_code',
                        'provider_title',
                        'original_name',
                        'stored_name',
                        'file_path',
                        'file_url',
                        'mime_type',
                        'uploaded_at',
                    ])
                    ->whereIn('import_log_id', $logs->pluck('id')->all())
                    ->orderByDesc('uploaded_at')
                    ->get()
                    ->groupBy('import_log_id')
                    ->map(function ($rows) {
                        $latest = $rows->first();

                        return [
                            'original_name' => $latest->original_name,
                            'stored_name' => $latest->stored_name,
                            'path' => $latest->file_path,
                            'url' => $latest->file_url,
                            'mime_type' => $latest->mime_type,
                            'company_code' => $latest->company_code,
                            'provider_title' => $latest->provider_title,
                            'uploaded_at' => optional($latest->uploaded_at)->toDateTimeString(),
                        ];
                    })
                    ->all();
            }

            $logs = $logs->map(function (ImportLog $log) use ($sourceFilesByImportLogId) {
                $sourceFile = $sourceFilesByImportLogId[$log->id] ?? null;

                return [
                    'id' => $log->id,
                    'enrollment_id' => $log->enrollment_id,
                    'import_date' => $log->import_date?->toDateTimeString(),
                    'total_principals' => (int) ($log->total_principals ?? 0),
                    'total_dependents' => (int) ($log->total_dependents ?? 0),
                    'principals_created' => (int) ($log->principals_created ?? 0),
                    'principals_updated' => (int) ($log->principals_updated ?? 0),
                    'dependents_created' => (int) ($log->dependents_created ?? 0),
                    'dependents_updated' => (int) ($log->dependents_updated ?? 0),
                    'status' => $log->status,
                    'error_message' => $log->error_message,
                    'date_format_detected' => $log->date_format_detected,
                    'date_format_confidence' => $log->date_format_confidence,
                    'import_details' => [
                        'source_file' => $sourceFile,
                    ],
                ];
            })->all();

            return response()->json([
                'data' => $logs,
                'count' => count($logs),
                'limit' => $limit,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to get import logs', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to get import logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download the original uploaded file attached to an import log.
     */
    public function downloadImportLog(int $id): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        try {
            $log = ImportLog::find($id);

            if (!$log) {
                return response()->json([
                    'message' => 'Import log not found.',
                ], 404);
            }

            $sourceFileRecord = ImportedEnrollmentFile::query()
                ->select([
                    'company_code',
                    'provider_title',
                    'original_name',
                    'stored_name',
                    'file_path',
                    'file_url',
                    'mime_type',
                    'uploaded_at',
                ])
                ->where('import_log_id', $log->id)
                ->orderByDesc('uploaded_at')
                ->first();

            $sourceFile = $sourceFileRecord ? [
                'original_name' => $sourceFileRecord->original_name,
                'stored_name' => $sourceFileRecord->stored_name,
                'path' => $sourceFileRecord->file_path,
                'url' => $sourceFileRecord->file_url,
                'mime_type' => $sourceFileRecord->mime_type,
                'company_code' => $sourceFileRecord->company_code,
                'provider_title' => $sourceFileRecord->provider_title,
                'uploaded_at' => optional($sourceFileRecord->uploaded_at)->toDateTimeString(),
            ] : [];

            $filenamePrefix = $log->enrollment_id
                ? 'enrollment-' . $log->enrollment_id
                : 'multi-provider';
            $timestamp = $log->import_date?->format('Y-m-d_His') ?? now()->format('Y-m-d_His');
            $filename = "import_log_{$filenamePrefix}_{$timestamp}.txt";

            $lines = [
                'IMPORT LOG REPORT',
                '=================',
                'Import Log ID: ' . $log->id,
                'Enrollment ID: ' . ($log->enrollment_id ?? 'N/A (multi-provider)'),
                'Import Date: ' . ($log->import_date?->toDateTimeString() ?? 'N/A'),
                'Status: ' . strtoupper((string) $log->status),
                'Date Format Detected: ' . ($log->date_format_detected ?? 'N/A'),
                'Date Format Confidence: ' . ($log->date_format_confidence ?? 'N/A'),
                '',
                'SUMMARY',
                '-------',
                'Total Principals: ' . (int) ($log->total_principals ?? 0),
                'Total Dependents: ' . (int) ($log->total_dependents ?? 0),
                'Principals Created: ' . (int) ($log->principals_created ?? 0),
                'Principals Updated: ' . (int) ($log->principals_updated ?? 0),
                'Dependents Created: ' . (int) ($log->dependents_created ?? 0),
                'Dependents Updated: ' . (int) ($log->dependents_updated ?? 0),
                '',
                'SOURCE FILE',
                '-----------',
                'Original Name: ' . ($sourceFile['original_name'] ?? 'N/A'),
                'Stored Name: ' . ($sourceFile['stored_name'] ?? 'N/A'),
                'MIME Type: ' . ($sourceFile['mime_type'] ?? 'N/A'),
                'Company Code: ' . ($sourceFile['company_code'] ?? 'N/A'),
                'Provider Title: ' . ($sourceFile['provider_title'] ?? 'N/A'),
                'Uploaded At: ' . ($sourceFile['uploaded_at'] ?? 'N/A'),
                '',
                'ERROR MESSAGE',
                '-------------',
                $log->error_message ?: 'N/A',
                '',
            ];

            $report = implode(PHP_EOL, $lines);

            return response()->streamDownload(
                fn () => print $report,
                $filename,
                [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to download import log', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to download import log',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

