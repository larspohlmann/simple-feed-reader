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

    public function deadlineFrom(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return $start->modify(\sprintf('+%d seconds', $this->seconds));
    }
}
