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
        $formats = ['Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'm-d-Y'];
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
