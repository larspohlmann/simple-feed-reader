<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** How many AppView profile calls one pass may spend on profile pictures, each asking for up to a page of actors. */
final readonly class ProfileImageBudgetModel
{
    /** The guarded fetcher's max_duration, the longest one call can take. */
    public const int CALL_SECONDS = 20;

    private function __construct(public int $calls)
    {
    }

    public static function calls(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A profile-picture budget cannot be negative.');
        }

        return new self($count);
    }

    /** The whole calls that end before $deadline, at most $maxCalls: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $maxCalls): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::calls(min($maxCalls, intdiv(max(0, $remaining), self::CALL_SECONDS)));
    }

    public function isEmpty(): bool
    {
        return $this->calls === 0;
    }
}
