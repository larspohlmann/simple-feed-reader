<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

use App\Service\SubscriptionHistory\Model\SubscriptionActivityModel;

final readonly class ReadingActivityModel
{
    /**
     * @param list<array{feedId: int, readCount: int}> $topFeedsByRead busiest feed first
     */
    public function __construct(
        public SubscriptionActivityModel $daily,
        public array $topFeedsByRead,
        public ReadingTotalsModel $totals,
    ) {
    }
}
