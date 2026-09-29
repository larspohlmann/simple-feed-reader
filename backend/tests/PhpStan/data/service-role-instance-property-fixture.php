<?php

declare(strict_types=1);

// A fixture for ServiceRoleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Tally {
    /** @noinspection AutoloadingIssuesInspection */
    final readonly class Scoreboard
    {
        private int $total;

        public static function double(int $score): int
        {
            return $score * 2;
        }
    }
}
