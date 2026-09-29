<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\LeadingEngagementCleaner;
use App\Service\Reader\DateLineRecognizer;
use App\Service\Reader\Factory\StrictDateFormatterFactory;
use App\Service\Reader\LeadingBlockJudge;
use App\Service\Reader\LeadingEngagementRules;

/** The leading-engagement cleaner over the real date, block and text rules, wired as the container wires it. */
final class LeadingEngagementCleaners
{
    private function __construct()
    {
    }

    public static function cleaner(): LeadingEngagementCleaner
    {
        $rules = new LeadingEngagementRules();

        return new LeadingEngagementCleaner(
            new DateLineRecognizer(new StrictDateFormatterFactory()),
            new LeadingBlockJudge($rules),
            $rules,
        );
    }
}
