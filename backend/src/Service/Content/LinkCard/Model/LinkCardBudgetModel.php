<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Model;

use App\Entity\ShareEnrichment;
use App\Service\Content\Reader\PageHeadFetcher;
use App\Service\Foundation\Clock\Support\DeadlineFit;

/** How many page fetches one pass may spend on link cards, and so how long it may take. */
final readonly class LinkCardBudgetModel
{
    private function __construct(public int $fetches)
    {
    }

    public static function fetches(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A link-card budget cannot be negative.');
        }

        if (self::computeDuration($count) >= ShareEnrichment::CLAIM_LEASE_SECONDS) {
            throw new \InvalidArgumentException('A link-card budget cannot outlast the claim lease.');
        }

        return new self($count);
    }

    /** The most fetches one pass may claim: every fetch must end before the claim lease lapses. */
    public static function largestPass(): int
    {
        return (int) ceil(ShareEnrichment::CLAIM_LEASE_SECONDS / PageHeadFetcher::MAX_DURATION_SECONDS) - 1;
    }

    /** The whole fetches that end before $deadline, at most $maxFetches: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches): self
    {
        $fitting = DeadlineFit::wholeUnits($deadline, $now, PageHeadFetcher::MAX_DURATION_SECONDS);

        return self::fetches(min($maxFetches, $fitting));
    }

    public function seconds(): float
    {
        return self::computeDuration($this->fetches);
    }

    public function fitsAfter(float $elapsedSeconds, int $fetches): bool
    {
        return $elapsedSeconds + self::computeDuration($fetches) <= $this->seconds();
    }

    public function isEmpty(): bool
    {
        return $this->fetches === 0;
    }

    private static function computeDuration(int $fetches): float
    {
        return $fetches * PageHeadFetcher::MAX_DURATION_SECONDS;
    }
}
