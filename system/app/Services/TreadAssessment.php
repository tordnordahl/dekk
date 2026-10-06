<?php
namespace App\Services;

class TreadAssessment
{
    // Workshop recommendation, not a legal assessment. Preserve existing summer thresholds.
    public static function status(?float $depth, string $season): string
    {
        if ($depth === null) return 'unknown';
        if ($depth < 3) return 'replace';
        if ($depth < 4 || ($season === 'winter' && $depth <= 4)) return 'attention';
        return 'good';
    }
}
