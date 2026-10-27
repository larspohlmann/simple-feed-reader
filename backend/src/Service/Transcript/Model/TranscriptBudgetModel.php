<?php

declare(strict_types=1);

namespace App\Service\Transcript\Model;

/** How many transcript fetches one pass may spend, each allowed FETCH_SECONDS. */
final readonly class TranscriptBudgetModel
{
    public const float FETCH_SECONDS = 4.0;

    private function __construct(public int $fetches)
    {
    }

    public static function fetches(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A transcript budget cannot be negative.');
        }

        return new self($count);
    }

    /** The whole fetches that end before $deadline, at most $maxFetches. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxFetches): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::fetches(min($maxFetches, (int) floor(max(0, $remaining) / self::FETCH_SECONDS)));
    }

    public function isEmpty(): bool
    {
        return $this->fetches === 0;
    }
}
