<?php

namespace Modules\ClientMasterlist\App\Services;

use Modules\ClientMasterlist\App\Models\Enrollee;
use Modules\ClientMasterlist\App\Models\Notification;

/**
 * VariableReplacementService
 * 
 * Handles template variable replacement and table generation for notifications.
 * Supports dynamic content insertion including enrollment links, data tables, and premium calculations.
 */
class VariableReplacementService
{
    /**
     * Replace variables in text with actual values
     * 
     * @param string $text Text containing variable placeholders
     * @param array $replacements Array of variable replacements
     * @return string Text with variables replaced
     */
    public function replaceVariables($text, $replacements = [])
    {
        if (!$text) {
            return $text;
        }

        foreach ($replacements as $key => $value) {
            // Case-sensitive replacement (original behavior)
            $text = str_replace('{{' . $key . '}}', $value, $text);

            // Case-insensitive replacement for uppercase variables
            $text = str_ireplace('{{' . $key . '}}', $value, $text);
        }

        return $text;
    }

    /**
     * Get variable replacements for a notification
     * 
     * @param Notification $notification The notification object
     * @param array $data Additional data for replacements
     * @return array Array of variable replacements
     */
    public function getVariableReplacements($notification, $data = [])
    {
        $enrollee = $this->getEnrolleeForReplacements($notification, $data);

        if (!$enrollee) {
            return $this->getDefaultReplacements($data);
        }

        $baseUrl = $this->getFormattedBaseUrl();
        $enrollmentLink = $this->generateEnrollmentLink($baseUrl, $enrollee);

        $replacements = [
            'enrollment_link' => $data['enrollment_link'] ?? $enrollmentLink,
            'coverage_start_date' => $this->getCoverageStartDate($enrollee, $data),
            'first_day_of_next_month' => $data['first_day_of_next_month'] ?? $this->getFirstDayOfNextMonth(),
            'date_today' => date('F j, Y'),
            'certification_table' => $this->generateCertificationTable($enrollee),
            'submission_table' => $this->generateSubmissionTable($enrollee),
        ];

        // Add uppercase versions for backward compatibility
        $replacements = array_merge($replacements, [
            'ENROLLMENT_LINK' => $replacements['enrollment_link'],
            'COVERAGE_START_DATE' => $replacements['coverage_start_date'],
            'FIRST_DAY_OF_NEXT_MONTH' => $replacements['first_day_of_next_month'],
            'DATE_TODAY' => $replacements['date_today'],
            'CERTIFICATION_TABLE' => $replacements['certification_table'],
            'SUBMISSION_TABLE' => $replacements['submission_table'],
        ]);

        return $replacements;
    }

    /**
     * Get the enrollee to use for variable replacements
     */
    private function getEnrolleeForReplacements($notification, $data)
    {
        // Prefer enrollee_id from $data
        if (!empty($data['enrollee_id'])) {
            return Enrollee::where('id', $data['enrollee_id'])
                ->whereNull('deleted_at')
                ->first();
        }

        // Fallback to notification enrollment_id
        if ($notification && isset($notification->enrollment_id)) {
            return Enrollee::where('enrollment_id', $notification->enrollment_id)
                ->whereNull('deleted_at')
                ->first();
        }

        return null;
    }

    /**
     * Get default replacements when no enrollee is available
     */
    private function getDefaultReplacements($data)
    {
        return [
            'enrollment_link' => $data['enrollment_link'] ?? '',
            'coverage_start_date' => $data['coverage_start_date'] ?? date('F j, Y'),
            'first_day_of_next_month' => $data['first_day_of_next_month'] ?? $this->getFirstDayOfNextMonth(),
            'date_today' => date('F j, Y'),
            'certification_table' => '',
            'submission_table' => '',
            'ENROLLMENT_LINK' => $data['enrollment_link'] ?? '',
            'COVERAGE_START_DATE' => $data['coverage_start_date'] ?? date('F j, Y'),
            'FIRST_DAY_OF_NEXT_MONTH' => $data['first_day_of_next_month'] ?? $this->getFirstDayOfNextMonth(),
            'DATE_TODAY' => date('F j, Y'),
            'CERTIFICATION_TABLE' => '',
            'SUBMISSION_TABLE' => '',
        ];
    }

    /**
     * Get formatted base URL for the frontend
     */
    private function getFormattedBaseUrl()
    {
        $baseUrl = env('FRONTEND_URL', '');
        $baseUrl = rtrim($baseUrl, '/');

        // Fix malformed URLs
        $baseUrl = preg_replace('/^https?:\/+/', 'https://', $baseUrl);

        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }

        return $baseUrl;
    }

    /**
     * Generate enrollment link for an enrollee
     */
    private function generateEnrollmentLink($baseUrl, $enrollee)
    {
        $link = $baseUrl . '/self-enrollment?id=' . $enrollee->uuid;
        return '<a href="' . $link . '">Self-Enrollment Portal</a>';
    }

    /**
     * Get coverage start date
     */
    private function getCoverageStartDate($enrollee, $data)
    {
        if (isset($data['coverage_start_date'])) {
            return $data['coverage_start_date'];
        }

        if ($enrollee && isset($enrollee->healthInsurance->coverage_start_date)) {
            return date('F j, Y', strtotime($enrollee->healthInsurance->coverage_start_date));
        }

        return date('F j, Y');
    }

    /**
     * Get first day of next month
     */
    private function getFirstDayOfNextMonth()
    {
        return date('F j, Y', strtotime('+1 month', strtotime(date('Y-m-01'))));
    }

    /**
     * Generate certification table HTML
     */
    public function generateCertificationTable($enrollee = null)
    {
        if (!$enrollee) {
            return '';
        }

        $rows = $this->prepareCertificationRows($enrollee);

        return $this->buildHtmlTable($rows, [
            'Relation',
            'Name',
            'Certificate #',
            'Status'
        ]);
    }

    /**
     * Prepare certification table rows
     */
    private function prepareCertificationRows($enrollee)
    {
        $rows = [];

        // Add principal row
        $rows[] = [
            'relation' => 'PRINCIPAL',
            'name' => trim(($enrollee->first_name ?? '') . ' ' . ($enrollee->last_name ?? '')),
            'certificate_number' => $this->getCertificateNumber($enrollee),
            'enrollment_status' => $enrollee->enrollment_status ?? '',
        ];

        // Add dependent rows
        if (method_exists($enrollee, 'dependents')) {
            foreach ($enrollee->dependents as $dependent) {
                $status = strtoupper($dependent->status ?? '');
                $enrollmentStatus = strtoupper($dependent->enrollment_status ?? '');

                if ($enrollmentStatus === 'SKIPPED' || $status === 'INACTIVE') {
                    continue;
                }

                $rows[] = [
                    'relation' => 'DEPENDENT',
                    'name' => trim(($dependent->first_name ?? '') . ' ' . ($dependent->last_name ?? '')),
                    'certificate_number' => $this->getCertificateNumber($dependent) ?? 'N/A',
                    'enrollment_status' => $dependent->enrollment_status ?? 'N/A',
                ];
            }
        }

        return $rows;
    }

    /**
     * Generate submission table HTML with premium computation
     */
    public function generateSubmissionTable($enrollee = null)
    {
        if (!$enrollee) {
            return '';
        }

        $rows = $this->prepareSubmissionRows($enrollee);
        $dependentsArr = $this->prepareDependentsArray($enrollee);

        $html = '<b>Below is the summary of your enrollment:</b><br />';
        $html .= $this->buildHtmlTable($rows, ['Relation', 'Name', 'Status']);
        $html .= $this->generatePremiumComputationSection($enrollee, $dependentsArr);

        return $html;
    }

    /**
     * Prepare submission table rows
     */
    private function prepareSubmissionRows($enrollee)
    {
        $rows = [];

        // Add principal row
        $rows[] = [
            'relation' => 'PRINCIPAL',
            'name' => trim(($enrollee->first_name ?? '') . ' ' . ($enrollee->last_name ?? '')),
            'enrollment_status' => $enrollee->enrollment_status ?? '',
        ];

        // Add dependent rows
        if (method_exists($enrollee, 'dependents')) {
            foreach ($enrollee->dependents as $dependent) {
                $rows[] = [
                    'relation' => 'DEPENDENT',
                    'name' => trim(($dependent->first_name ?? '') . ' ' . ($dependent->last_name ?? '')),
                    'enrollment_status' => in_array($dependent->enrollment_status, ['OVERAGE', 'SKIPPED'])
                        ? $dependent->enrollment_status
                        : '--',
                ];
            }
        }

        return $rows;
    }

    /**
     * Prepare dependents array for premium computation
     */
    private function prepareDependentsArray($enrollee)
    {
        $dependentsArr = [];

        if (method_exists($enrollee, 'dependents')) {
            foreach ($enrollee->dependents as $dependent) {
                $depArr = is_array($dependent) ? $dependent : (array)$dependent;
                $depArr['is_skipping'] = $dependent->enrollment_status === 'SKIPPED' ? 1 : 0;
                $dependentsArr[] = $depArr;
            }
        }

        return $dependentsArr;
    }

    /**
     * Generate premium computation section HTML
     */
    private function generatePremiumComputationSection($enrollee, $dependentsArr)
    {
        $premium = $this->calculatePremium($enrollee);

        if ($premium <= 0 || count($dependentsArr) === 0) {
            return '';
        }

        $premiumComputation = $enrollee->enrollment->premium_computation ?? null;
        $result = $this->computePremiumBreakdown($dependentsArr, $premium, $premiumComputation);

        $html = '<div style="margin-top:18px; margin-bottom:18px; padding:12px; background:#ebf8ff; border-radius:8px;">';
        $html .= '<div style="font-weight:bold; color:#2b6cb0; margin-bottom:8px; font-size:16px;">Premium Computation</div>';
        $html .= '<table style="width:100%; font-size:18px; margin-bottom:8px;"><tbody>';
        $html .= '<tr><td>' . ($enrollee->enrollment->premium_variable ?? "TOTAL") . ':</td><td style="font-weight:bold;">₱ ' . number_format($result['annual'], 2) . '</td></tr>';
        $html .= '<tr style="' . ($enrollee->enrollment->with_monthly ? "" : "display:none;") . '"><td>MONTHLY:</td><td style="font-weight:bold;font-size:18px;">₱ ' . number_format($result['monthly'], 2) . '</td></tr>';
        $html .= '</tbody></table>';
        $html .= '<div style="font-weight:bold; margin-bottom:4px; font-size:16px;">Breakdown</div>';
        $html .= '<table style="border-collapse:collapse; width:100%; font-size:15px;"><tbody>';

        foreach ($result['breakdown'] as $row) {
            $html .= '<tr style="border-bottom:1px solid #e2e8f0;">';
            $html .= '<td>' . htmlspecialchars($row['dependentCount']) . ' Dependent:<br />' . htmlspecialchars($row['percentage']) . ' of ₱ ' . number_format($premium, 2) . '</td>';
            $html .= '<td style="font-weight:bold;">₱ ' . number_format($row['computed'], 2) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Calculate premium for an enrollee
     */
    private function calculatePremium($enrollee)
    {
        $premium = 0;

        // Prefer enrollment premium if present
        if (!empty($enrollee->enrollment) && isset($enrollee->enrollment->premium) && $enrollee->enrollment->premium > 0) {
            $premium = $enrollee->enrollment->premium;
        }

        // Fallback to health insurance premium
        if (!empty($enrollee->healthInsurance) && isset($enrollee->healthInsurance->premium) && $enrollee->healthInsurance->premium > 0) {
            $premium = $enrollee->healthInsurance->premium;
        }

        // Check if company paid
        if (!empty($enrollee->healthInsurance) && $enrollee->healthInsurance->is_company_paid) {
            $premium = 0;
        }

        return $premium;
    }

    /**
     * Compute premium breakdown for dependents
     */
    public function computePremiumBreakdown($dependents = [], $bill = 0, $premiumComputation = null)
    {
        $breakdown = [];
        $percentMap = $this->parsePremiumComputation($premiumComputation);

        // Only count non-skipped dependents
        $depIndex = 0;
        foreach ($dependents as $item) {
            if ($this->isDependentSkipping($item)) {
                continue;
            }

            $depIndex++;
            $percent = $this->getPercentageForDependent($depIndex, $percentMap);

            $breakdown[] = [
                'dependentCount' => (string)$depIndex,
                'percentage' => $percent . '%',
                'computed' => $bill * ($percent / 100),
            ];
        }

        $annual = array_reduce($breakdown, function ($sum, $row) {
            return $sum + $row['computed'];
        }, 0);

        return [
            'breakdown' => $breakdown,
            'annual' => $annual,
            'monthly' => $annual / 12,
        ];
    }

    /**
     * Parse premium computation string into percentage map
     */
    private function parsePremiumComputation($premiumComputation)
    {
        $percentMap = [];

        if (is_string($premiumComputation) && trim($premiumComputation) !== '') {
            $parts = array_map('trim', explode(',', $premiumComputation));
            foreach ($parts as $part) {
                $split = explode(':', $part);
                if (count($split) === 2 && is_numeric($split[1])) {
                    $label = trim($split[0]);
                    $percentMap[$label] = floatval($split[1]);
                }
            }
        }

        return $percentMap;
    }

    /**
     * Check if dependent is skipping enrollment
     */
    private function isDependentSkipping($item)
    {
        if (!isset($item['is_skipping'])) {
            return false;
        }

        $val = $item['is_skipping'];
        return ($val === true || $val === 1 || $val === '1');
    }

    /**
     * Get percentage for a specific dependent
     */
    private function getPercentageForDependent($depIndex, $percentMap)
    {
        // Check for specific dependent index
        if (isset($percentMap[(string)$depIndex])) {
            return $percentMap[(string)$depIndex];
        }

        // Check for 'REST' key (case-insensitive)
        foreach ($percentMap as $k => $v) {
            if (strtoupper($k) === 'REST') {
                return $v;
            }
        }

        return 0;
    }

    /**
     * Get certificate number from enrollee or health insurance
     */
    private function getCertificateNumber($person)
    {
        if (isset($person->healthInsurance) && !empty($person->healthInsurance->certificate_number)) {
            return $person->healthInsurance->certificate_number;
        }

        return $person->certificate_number ?? '';
    }

    /**
     * Build HTML table from rows and headers
     */
    private function buildHtmlTable($rows, $headers)
    {
        $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';

        // Add header
        $html .= '<thead><tr style="background:#f3f3f3;">';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead>';

        // Add body
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }
}
