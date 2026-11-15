<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution\Model;

/** How many short links one pass may follow, each budgeted at two attempts. */
final readonly class LinkResolutionBudgetModel
{
    public const float ATTEMPT_SECONDS = 3.0;
    public const float MAX_SECONDS = 2 * self::ATTEMPT_SECONDS;
    public const int PER_PASS = 5;

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

    public static function perPass(): self
    {
        return new self(self::PER_PASS);
    }

    /** The whole links that end before $deadline, at most a pass: none once less than one fits. */
    public static function fittingBefore(\DateTimeImmutable $deadline, \DateTimeImmutable $now): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::links(min(self::PER_PASS, (int) floor(max(0, $remaining) / self::MAX_SECONDS)));
    }

    /** Whether one more worst-case link still fits after $elapsedSeconds. */
    public function fitsAfter(float $elapsedSeconds): bool
    {
        return $elapsedSeconds <= ($this->links - 1) * self::MAX_SECONDS;
    }

    public function isEmpty(): bool
    {
        return $this->links === 0;
    }
}
