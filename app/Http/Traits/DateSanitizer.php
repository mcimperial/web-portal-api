<?php

namespace App\Http\Traits;

trait DateSanitizer
{
    public function sanitizeDate($value)
    {
        if (empty($value) || $value === null) return null;
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
        // Acceptable formats: Y-m-d, Y/m/d, d-m-Y, d/m/Y, m/d/Y, m-d-Y
        // Handle 2-digit years first (e.g., 29/1/68 should be 1968, not 2068)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $value, $matches)) {
            $first = (int)$matches[1];
            $second = (int)$matches[2];
            $year = (int)$matches[3];

            // Convert 2-digit year to 4-digit year
            // Assume years 00-30 are 2000-2030, years 31-99 are 1931-1999
            if ($year <= 30) {
                $year += 2000;
            } else {
                $year += 1900;
            }

            // If first number > 12, it must be d/m/Y format (day/month/year)
            if ($first > 12) {
                $day = $first;
                $month = $second;
                if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                    $normalizedValue = sprintf('%02d/%02d/%04d', $day, $month, $year);
                    $dt = \DateTime::createFromFormat('d/m/Y', $normalizedValue);
                    if ($dt) {
                        $date = $dt->format('Y-m-d');
                        if ($this->isValidDate($date)) {
                            return $date;
                        }
                    }
                }
            }
            // If second number > 12, it must be m/d/Y format (month/day/year)
            elseif ($second > 12) {
                $month = $first;
                $day = $second;
                if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                    $normalizedValue = sprintf('%02d/%02d/%04d', $month, $day, $year);
                    $dt = \DateTime::createFromFormat('m/d/Y', $normalizedValue);
                    if ($dt) {
                        $date = $dt->format('Y-m-d');
                        if ($this->isValidDate($date)) {
                            return $date;
                        }
                    }
                }
            }
            // If both are <= 12, assume d/m/Y format (European style)
            else {
                $day = $first;
                $month = $second;
                if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                    $normalizedValue = sprintf('%02d/%02d/%04d', $day, $month, $year);
                    $dt = \DateTime::createFromFormat('d/m/Y', $normalizedValue);
                    if ($dt) {
                        $date = $dt->format('Y-m-d');
                        if ($this->isValidDate($date)) {
                            return $date;
                        }
                    }
                }
            }
        }

        // Try d/m/Y format for 4-digit years like 7/6/1990 (7th June 1990)
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
            $first = (int)$matches[1];
            $second = (int)$matches[2];
            $year = (int)$matches[3];

            // If first number > 12, it must be d/m/Y format (day/month/year)
            if ($first > 12) {
                $day = $first;
                $month = $second;
                $normalizedValue = sprintf('%02d/%02d/%04d', $day, $month, $year);
                $dt = \DateTime::createFromFormat('d/m/Y', $normalizedValue);
                if ($dt && $month >= 1 && $month <= 12) {
                    $date = $dt->format('Y-m-d');
                    if ($this->isValidDate($date)) {
                        return $date;
                    }
                }
            }
            // If second number > 12, it must be m/d/Y format (month/day/year)
            elseif ($second > 12) {
                $month = $first;
                $day = $second;
                $normalizedValue = sprintf('%02d/%02d/%04d', $month, $day, $year);
                $dt = \DateTime::createFromFormat('m/d/Y', $normalizedValue);
                if ($dt && $month >= 1 && $month <= 12) {
                    $date = $dt->format('Y-m-d');
                    if ($this->isValidDate($date)) {
                        return $date;
                    }
                }
            }
            // If both are <= 12, assume d/m/Y format (European style)
            else {
                $day = $first;
                $month = $second;
                $normalizedValue = sprintf('%02d/%02d/%04d', $day, $month, $year);
                $dt = \DateTime::createFromFormat('d/m/Y', $normalizedValue);
                if ($dt && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                    $date = $dt->format('Y-m-d');
                    if ($this->isValidDate($date)) {
                        return $date;
                    }
                }
            }
        }

        // Try other formats
        $formats = ['Y-m-d', 'Y/m/d', 'd-m-Y', 'm/d/Y', 'm-d-Y'];
        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt && $dt->format($format) === $value) {
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

    public function isValidDate($date)
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }
}
