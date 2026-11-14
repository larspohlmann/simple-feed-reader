<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\ShareEnrichment;
use App\Service\Fetch\Model\ApiBudgetModel;

/** How many self-reply threads one pass may read, each starting only while its longest call still ends inside the lease. */
final readonly class SelfThreadBudgetModel
{
    private const float LEASE_MARGIN_SECONDS = 10.0;

    private function __construct(public int $threads)
    {
    }

    public static function threads(int $count): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('A self-thread budget reads at least one thread.');
        }

        return new self($count);
    }

    public function fitsAfter(float $elapsedSeconds): bool
    {
        return $elapsedSeconds + ApiBudgetModel::STANDARD_MAX_DURATION_SECONDS + self::LEASE_MARGIN_SECONDS
            <= ShareEnrichment::CLAIM_LEASE_SECONDS;
    }
}
