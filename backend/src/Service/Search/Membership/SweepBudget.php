<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

/** How long one sweep run may work before it stops and leaves the rest to the next run. */
final readonly class SweepBudget
{
    private function __construct(private int $seconds)
    {
    }

    public static function seconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('A sweep budget cannot be negative.');
        }

        return new self($seconds);
    }

    /** The smaller of $cap and what is left until $deadline — nothing, once it has passed. */
    public static function remainingUntil(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $cap): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::seconds(max(0, min($cap, $remaining)));
    }

    public function deadlineFrom(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return $start->modify(\sprintf('+%d seconds', $this->seconds));
    }
}
