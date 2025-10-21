<?php

namespace App\Http\Helpers;

require_once __DIR__ . '/../Traits/DateSanitizer.php';

use App\Http\Traits\DateSanitizer;

class CsvDateValidator
{
    use DateSanitizer;

    /**
     * Validate and analyze CSV date formats
     * 
     * @param array $csvData Array of CSV rows
     * @param array $dateColumns Array of column names that contain dates
     * @return array Validation results and recommendations
     */
    public function validateCsvDates(array $csvData, array $dateColumns)
    {
        $results = [
            'is_valid' => true,
            'total_rows' => count($csvData),
            'date_analysis' => null,
            'issues' => [],
            'recommendations' => [],
            'sanitized_sample' => []
        ];

        // Extract all date values
        $allDates = [];
        foreach ($csvData as $rowIndex => $row) {
            foreach ($dateColumns as $column) {
                if (isset($row[$column]) && !empty($row[$column])) {
                    $allDates[] = [
                        'value' => $row[$column],
                        'row' => $rowIndex,
                        'column' => $column
                    ];
                }
            }
        }

        if (empty($allDates)) {
            $results['is_valid'] = false;
            $results['issues'][] = 'No date values found in specified columns';
            return $results;
        }

        // Perform bulk analysis
        $dateValues = array_column($allDates, 'value');
        $analysis = $this->analyzeDateFormats($dateValues);
        $results['date_analysis'] = $analysis;

        // Check confidence level
        if ($analysis['confidence'] < 70) {
            $results['is_valid'] = false;
            $results['issues'][] = "Low confidence in date format detection ({$analysis['confidence']}%)";
        }

        // Check for invalid dates
        if ($analysis['invalid_dates'] > 0) {
            $results['is_valid'] = false;
            $results['issues'][] = "{$analysis['invalid_dates']} invalid dates found";
        }

        // Generate recommendations
        if ($analysis['ambiguous_dates'] > 0) {
            $results['recommendations'][] = "Consider standardizing {$analysis['ambiguous_dates']} ambiguous dates";
        }

        if ($analysis['recommended_format'] === 'auto') {
            $results['recommendations'][] = "Mixed date formats detected - consider using consistent format";
        }

        // Process sample data for preview
        $bulkResult = $this->sanitizeBulkDates($dateValues);
        $sampleSize = min(5, count($csvData));

        for ($i = 0; $i < $sampleSize; $i++) {
            $row = $csvData[$i];
            $sanitizedRow = [];

            foreach ($dateColumns as $column) {
                if (isset($row[$column]) && !empty($row[$column])) {
                    $original = $row[$column];
                    $sanitized = $this->sanitizeDate($original, $analysis['recommended_format']);
                    $sanitizedRow[$column] = [
                        'original' => $original,
                        'sanitized' => $sanitized,
                        'changed' => $original !== $sanitized
                    ];
                }
            }

            if (!empty($sanitizedRow)) {
                $results['sanitized_sample'][] = [
                    'row_index' => $i,
                    'dates' => $sanitizedRow
                ];
            }
        }

        return $results;
    }

    /**
     * Sanitize an entire CSV dataset
     * 
     * @param array $csvData Array of CSV rows
     * @param array $dateColumns Array of column names that contain dates
     * @param string $preferredFormat Optional format preference
     * @return array Sanitized data and processing report
     */
    public function sanitizeCsvDates(array $csvData, array $dateColumns, string $preferredFormat = 'auto')
    {
        $report = [
            'total_rows_processed' => 0,
            'dates_sanitized' => 0,
            'dates_failed' => 0,
            'changes_made' => [],
            'errors' => []
        ];

        $sanitizedData = [];

        foreach ($csvData as $rowIndex => $row) {
            $sanitizedRow = $row;
            $rowHasChanges = false;

            foreach ($dateColumns as $column) {
                if (isset($row[$column]) && !empty($row[$column])) {
                    $original = $row[$column];
                    $sanitized = $this->sanitizeDate($original, $preferredFormat);

                    if ($sanitized) {
                        $sanitizedRow[$column] = $sanitized;
                        $report['dates_sanitized']++;

                        if ($original !== $sanitized) {
                            $report['changes_made'][] = [
                                'row' => $rowIndex,
                                'column' => $column,
                                'original' => $original,
                                'sanitized' => $sanitized
                            ];
                            $rowHasChanges = true;
                        }
                    } else {
                        $report['dates_failed']++;
                        $report['errors'][] = [
                            'row' => $rowIndex,
                            'column' => $column,
                            'value' => $original,
                            'error' => 'Could not parse date'
                        ];
                    }
                }
            }

            $sanitizedData[] = $sanitizedRow;
            $report['total_rows_processed']++;
        }

        return [
            'data' => $sanitizedData,
            'report' => $report
        ];
    }

    /**
     * Generate CSV validation report for user
     */
    public function generateValidationReport(array $validationResults)
    {
        $report = "CSV DATE VALIDATION REPORT\n";
        $report .= str_repeat("=", 50) . "\n\n";

        $analysis = $validationResults['date_analysis'];

        $report .= "SUMMARY:\n";
        $report .= "Total rows: {$validationResults['total_rows']}\n";
        $report .= "Validation status: " . ($validationResults['is_valid'] ? "✓ PASSED" : "✗ FAILED") . "\n\n";

        if ($analysis) {
            $report .= "DATE FORMAT ANALYSIS:\n";
            $report .= "Total dates: {$analysis['total_dates']}\n";
            $report .= "DD/MM indicators: {$analysis['dmy_indicators']}\n";
            $report .= "MM/DD indicators: {$analysis['mdy_indicators']}\n";
            $report .= "Ambiguous dates: {$analysis['ambiguous_dates']}\n";
            $report .= "Invalid dates: {$analysis['invalid_dates']}\n";
            $report .= "Recommended format: {$analysis['recommended_format']}\n";
            $report .= "Confidence: " . number_format($analysis['confidence'], 1) . "%\n\n";
        }

        if (!empty($validationResults['issues'])) {
            $report .= "ISSUES FOUND:\n";
            foreach ($validationResults['issues'] as $issue) {
                $report .= "- $issue\n";
            }
            $report .= "\n";
        }

        if (!empty($validationResults['recommendations'])) {
            $report .= "RECOMMENDATIONS:\n";
            foreach ($validationResults['recommendations'] as $recommendation) {
                $report .= "- $recommendation\n";
            }
            $report .= "\n";
        }

        if (!empty($validationResults['sanitized_sample'])) {
            $report .= "SAMPLE SANITIZATION PREVIEW:\n";
            foreach ($validationResults['sanitized_sample'] as $sample) {
                $report .= "Row {$sample['row_index']}:\n";
                foreach ($sample['dates'] as $column => $dateInfo) {
                    $status = $dateInfo['changed'] ? "CHANGED" : "unchanged";
                    $report .= "  $column: {$dateInfo['original']} → {$dateInfo['sanitized']} ($status)\n";
                }
                $report .= "\n";
            }
        }

        return $report;
    }
}

// Example usage for testing
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $validator = new CsvDateValidator();

    // Test with your sample data
    $sampleCsv = [
        ['employee_id' => 'test1', 'birth_date' => '24/08/1980', 'start_date' => '11/3/2025'],
        ['employee_id' => 'test2', 'birth_date' => '2/3/1983', 'start_date' => '14/09/2023'],
        ['employee_id' => 'test3', 'birth_date' => '16/11/1993', 'start_date' => '10/4/2025'],
    ];

    $dateColumns = ['birth_date', 'start_date'];

    $results = $validator->validateCsvDates($sampleCsv, $dateColumns);
    echo $validator->generateValidationReport($results);

    if ($results['is_valid']) {
        echo "\nSANITIZING DATA...\n";
        $sanitized = $validator->sanitizeCsvDates($sampleCsv, $dateColumns);
        echo "Changes made: " . count($sanitized['report']['changes_made']) . "\n";
        echo "Errors: " . count($sanitized['report']['errors']) . "\n";
    }
}
