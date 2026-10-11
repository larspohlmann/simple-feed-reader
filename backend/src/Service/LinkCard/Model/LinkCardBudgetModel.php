<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

use App\Entity\ShareEnrichment;
use App\Service\Reader\HtmlPageFetcher;

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

        if ($count * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS >= ShareEnrichment::CLAIM_LEASE_SECONDS) {
            throw new \InvalidArgumentException('A link-card budget cannot outlast the claim lease.');
        }

        return new self($count);
    }

    /** The whole fetches that end before $deadline, at most $maxFetches: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();
        $fitting = (int) floor(max(0, $remaining) / HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS);

        return self::fetches(min($maxFetches, $fitting));
    }

    public function seconds(): float
    {
        return $this->fetches * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS;
    }

    public function isEmpty(): bool
    {
        return $this->fetches === 0;
    }
}
