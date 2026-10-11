<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

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

        return new self($count);
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
