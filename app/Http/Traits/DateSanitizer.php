<?php

namespace App\Http\Traits;

trait DateSanitizer
{
    // Configuration property to determine date format preference
    protected $preferredDateFormat = 'auto'; // 'auto', 'dmy', 'mdy'

    /**
     * Sanitize date with smart format detection
     * 
     * @param mixed $value The date value to sanitize
     * @param string $preferredFormat Optional format preference: 'auto', 'dmy', 'mdy'
     * @return string|null Sanitized date in Y-m-d format or null if invalid
     */
    public function sanitizeDate($value, $preferredFormat = null)
    {
        if (empty($value) || $value === null) return null;

        // Use provided format preference or fallback to property/default
        $format = $preferredFormat ?? $this->preferredDateFormat ?? 'auto';

        // If already in Y-m-d format and valid, return as-is
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            if ($this->isValidDate($value)) {
                return $value;
            }
        }

        // Handle Excel serial date numbers (only if reasonable range)
        if (is_numeric($value)) {
            $num = (float)$value;
            if ($num > 0 && $num < 60000) {
                // Excel's epoch starts at 1899-12-30
                $base = strtotime('1899-12-30');
                $days = (int)$num;
                $timestamp = $base + ($days * 86400);
                $date = date('Y-m-d', $timestamp);
                if ($this->isValidDate($date)) {
                    return $date;
                } else {
                    return null;
                }
            } else {
                // If numeric but not in Excel range, set to null
                return null;
            }
        }

        // Handle slash-separated dates with improved logic
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $value, $matches)) {
            return $this->parseDateSlashFormat($matches[1], $matches[2], $matches[3], $format);
        }

        // Try other standard formats
        $formats = ['Y-m-d', 'Y/m/d', 'd-m-Y', 'm/d/Y', 'm-d-Y'];
        foreach ($formats as $testFormat) {
            $dt = \DateTime::createFromFormat($testFormat, $value);
            if ($dt && $dt->format($testFormat) === $value) {
                $date = $dt->format('Y-m-d');
                if ($this->isValidDate($date)) {
                    return $date;
                } else {
                    return null;
                }
            }
        }

        // Try strtotime fallback
        $timestamp = strtotime($value);
        if ($timestamp && $timestamp > 0) {
            $date = date('Y-m-d', $timestamp);
            if ($this->isValidDate($date)) {
                return $date;
            } else {
                return null;
            }
        }

        // If not valid, return null
        return null;
    }

    /**
     * Parse slash-separated date with intelligent format detection
     */
    private function parseDateSlashFormat($first, $second, $year, $preferredFormat)
    {
        $first = (int)$first;
        $second = (int)$second;
        $year = (int)$year;

        // Convert 2-digit year to 4-digit year
        if ($year < 100) {
            if ($year <= 30) {
                $year += 2000;
            } else {
                $year += 1900;
            }
        }

        // If first number > 12, it must be day (d/m/Y format)
        if ($first > 12) {
            return $this->tryCreateDate($first, $second, $year, 'd/m/Y');
        }

        // If second number > 12, it must be day (m/d/Y format)  
        if ($second > 12) {
            return $this->tryCreateDate($first, $second, $year, 'm/d/Y');
        }

        // Both numbers are <= 12, ambiguous case
        // Use format preference or smart detection
        switch ($preferredFormat) {
            case 'mdy':
                // Prefer MM/DD/YYYY (US format)
                return $this->tryCreateDate($first, $second, $year, 'm/d/Y')
                    ?? $this->tryCreateDate($first, $second, $year, 'd/m/Y');

            case 'dmy':
                // Prefer DD/MM/YYYY (European format)  
                return $this->tryCreateDate($first, $second, $year, 'd/m/Y')
                    ?? $this->tryCreateDate($first, $second, $year, 'm/d/Y');

            case 'auto':
            default:
                // Smart detection based on context
                return $this->smartFormatDetection($first, $second, $year);
        }
    }

    /**
     * Smart format detection for ambiguous dates
     */
    private function smartFormatDetection($first, $second, $year)
    {
        // Context-based heuristics for format detection

        // Strong preference for DD/MM format for Philippines/European context
        // Only consider MM/DD if DD/MM would create an invalid date

        // Try DD/MM first - this is the expected format for Philippines data
        $ddmmResult = $this->tryCreateDate($first, $second, $year, 'd/m/Y');
        if ($ddmmResult) {
            return $ddmmResult;
        }

        // Only fall back to MM/DD if DD/MM failed (invalid date)
        return $this->tryCreateDate($first, $second, $year, 'm/d/Y');
    }
    /**
     * Try to create a valid date from components using specified format
     */
    private function tryCreateDate($first, $second, $year, $format)
    {
        try {
            switch ($format) {
                case 'd/m/Y':
                    $day = $first;
                    $month = $second;
                    break;
                case 'm/d/Y':
                    $month = $first;
                    $day = $second;
                    break;
                default:
                    return null;
            }

            // Validate ranges
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }

            $dateString = sprintf('%04d-%02d-%02d', $year, $month, $day);

            // Verify the date is actually valid (handles leap years, month lengths, etc.)
            $dt = \DateTime::createFromFormat('Y-m-d', $dateString);
            if ($dt && $dt->format('Y-m-d') === $dateString) {
                return $dateString;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isValidDate($date)
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }

    /**
     * Analyze an array of dates to detect the most likely format
     * Useful for bulk import operations
     * 
     * @param array $dates Array of date strings
     * @return array Analysis results with recommended format
     */
    public function analyzeDateFormats(array $dates)
    {
        $dmyCount = 0;
        $mdyCount = 0;
        $unambiguousCount = 0;
        $invalidCount = 0;
        $ambiguousCount = 0;

        foreach ($dates as $date) {
            if (empty($date)) continue;

            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $date, $matches)) {
                $first = (int)$matches[1];
                $second = (int)$matches[2];

                if ($first > 12 && $second <= 12) {
                    // Must be DD/MM format
                    $dmyCount++;
                    $unambiguousCount++;
                } elseif ($second > 12 && $first <= 12) {
                    // Must be MM/DD format
                    $mdyCount++;
                    $unambiguousCount++;
                } elseif ($first <= 12 && $second <= 12) {
                    // Ambiguous
                    $ambiguousCount++;
                } else {
                    $invalidCount++;
                }
            } else {
                // Try other formats or invalid
                $sanitized = $this->sanitizeDate($date);
                if ($sanitized) {
                    $unambiguousCount++;
                } else {
                    $invalidCount++;
                }
            }
        }

        $total = count(array_filter($dates));

        // AGGRESSIVE DD/MM FORMAT ENFORCEMENT
        // If there's even ONE clear DD/MM indicator, ALL dates are DD/MM format
        $recommendedFormat = 'dmy'; // Default to DD/MM for Philippines context

        if ($dmyCount > 0) {
            // ANY DD/MM indicator means EVERYTHING is DD/MM format
            $recommendedFormat = 'dmy';
        } elseif ($mdyCount > 0 && $dmyCount == 0) {
            // Only use MM/DD if there are MM/DD indicators AND zero DD/MM indicators
            $recommendedFormat = 'mdy';
        }

        // Log the decision for debugging
        if ($dmyCount > 0) {
            error_log("DateSanitizer: Found {$dmyCount} DD/MM indicators - forcing ALL dates to DD/MM format");
        }
        return [
            'total_dates' => $total,
            'dmy_indicators' => $dmyCount,
            'mdy_indicators' => $mdyCount,
            'ambiguous_dates' => $ambiguousCount,
            'invalid_dates' => $invalidCount,
            'unambiguous_dates' => $unambiguousCount,
            'recommended_format' => $recommendedFormat,
            'confidence' => $total > 0 ? ($unambiguousCount / $total) * 100 : 0
        ];
    }

    /**
     * Sanitize dates in bulk with format detection
     * 
     * @param array $dates Array of date strings
     * @param string $preferredFormat Optional format preference
     * @return array Sanitized dates with analysis
     */
    public function sanitizeBulkDates(array $dates, $preferredFormat = null)
    {
        // First analyze to determine best format if not specified
        if (!$preferredFormat || $preferredFormat === 'auto') {
            $analysis = $this->analyzeDateFormats($dates);
            $detectedFormat = $analysis['recommended_format'];
        } else {
            $detectedFormat = $preferredFormat;
        }

        $results = [];
        $issues = [];

        foreach ($dates as $index => $date) {
            if (empty($date)) {
                $results[] = null;
                continue;
            }

            $sanitized = $this->sanitizeDate($date, $detectedFormat);
            $results[] = $sanitized;

            if (!$sanitized) {
                $issues[] = [
                    'index' => $index,
                    'original' => $date,
                    'issue' => 'Could not parse date'
                ];
            }
        }

        return [
            'sanitized_dates' => $results,
            'detected_format' => $detectedFormat,
            'issues' => $issues,
            'success_rate' => count($dates) > 0 ? (count(array_filter($results)) / count($dates)) * 100 : 0
        ];
    }
}
