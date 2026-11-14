<?php

declare(strict_types=1);

namespace App\Service\Library\Reading\Model;

use App\Service\Library\SubscriptionHistory\Model\SubscriptionActivityModel;

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
