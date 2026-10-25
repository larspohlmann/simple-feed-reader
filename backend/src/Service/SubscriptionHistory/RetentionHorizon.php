<?php

declare(strict_types=1);

namespace App\Service\SubscriptionHistory;

use App\Service\Retention\RetentionSettings;
use App\Service\SubscriptionHistory\Model\RetentionHorizonModel;
use App\Service\SubscriptionHistory\Support\UtcDay;

/** The age pass deletes entries older than N days from now, so day today − N is partial and today − (N − 1) whole. */
final readonly class RetentionHorizon
{
    public function __construct(private RetentionSettings $settings)
    {
    }

    public function asOf(string $today): RetentionHorizonModel
    {
        $windows = $this->settings->current();

        return new RetentionHorizonModel(
            UtcDay::shift($today, 1 - $windows->entryDays),
            UtcDay::shift($today, 1 - $windows->socialDays),
        );
    }
}
