<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Support;

/** The schedules a profile may run on, in hours; null is "only by hand". Shared by the validator and the JSON. */
final class ProfileSchedule
{
    public const array INTERVAL_CHOICES = [null, 6, 12, 24, 48, 168];

    private function __construct()
    {
    }
}
