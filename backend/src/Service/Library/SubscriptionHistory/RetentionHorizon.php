<?php

declare(strict_types=1);

namespace App\Service\Library\SubscriptionHistory;

use App\Service\Ingestion\Retention\RetentionSettings;
use App\Service\Library\SubscriptionHistory\Model\RetentionHorizonModel;
use App\Service\Library\SubscriptionHistory\Support\UtcDay;

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
