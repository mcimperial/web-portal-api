<?php

require_once __DIR__ . '/app/Http/Traits/DateSanitizer.php';

use App\Http\Traits\DateSanitizer;

class TestClass
{
    use DateSanitizer;
}

$tester = new TestClass();

// Test the problematic cases with detailed analysis
$problematicDates = [
    '2/3/1983',    // Could be Feb 3 or Mar 2
    '8/6/1994',    // Could be Aug 6 or Jun 8
    '6/12/1993',   // Could be Jun 12 or Dec 6
    '3/11/1997',   // Could be Mar 11 or Nov 3
    '11/3/2025',   // Could be Nov 3 or Mar 11
    '10/4/2025',   // Could be Oct 4 or Apr 10
    '1/3/2024',    // Could be Jan 3 or Mar 1
    '4/9/2025'     // Could be Apr 9 or Sep 4
];

echo "Detailed Analysis of Ambiguous Dates:\n";
echo str_repeat("=", 60) . "\n";

foreach ($problematicDates as $date) {
    $sanitized = $tester->sanitizeDate($date);

    // Manual analysis - what would it be in different formats?
    $parts = explode('/', $date);
    $first = $parts[0];
    $second = $parts[1];
    $year = $parts[2];

    $asDDMM = sprintf('%s-%02d-%02d', $year, $second, $first);  // DD/MM/YYYY
    $asMMDD = sprintf('%s-%02d-%02d', $year, $first, $second);  // MM/DD/YYYY

    printf(
        "%-10s → %-12s | DD/MM: %s | MM/DD: %s\n",
        $date,
        $sanitized,
        $asDDMM,
        $asMMDD
    );
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Current logic assumes DD/MM/YYYY (European) format when ambiguous\n";
echo "This may cause issues if source data uses MM/DD/YYYY (US) format\n";
