<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution\Model;

use App\Service\Content\LinkResolution\LinkLanding;

/** How many short links one pass may follow, each costing at most LinkLanding::MAX_SECONDS. */
final readonly class LinkResolutionBudgetModel
{
    private function __construct(public int $links)
    {
    }

    public static function links(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A link-resolution budget cannot be negative.');
        }

        return new self($count);
    }

    /** The whole links that end before $deadline, at most $maxLinks: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxLinks): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::links(min($maxLinks, (int) floor(max(0, $remaining) / LinkLanding::MAX_SECONDS)));
    }

    /** Whether one more worst-case link still fits after $elapsedSeconds. */
    public function fitsAfter(float $elapsedSeconds): bool
    {
        return $elapsedSeconds + LinkLanding::MAX_SECONDS <= $this->links * LinkLanding::MAX_SECONDS;
    }

    public function isEmpty(): bool
    {
        return $this->links === 0;
    }
}
