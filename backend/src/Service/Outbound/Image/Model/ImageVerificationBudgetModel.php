<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

/** How long one image-verification pass may work before it leaves the rest to the next pass. */
final readonly class ImageVerificationBudgetModel
{
    private function __construct(private int $seconds)
    {
    }

    public static function seconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('An image-verification budget cannot be negative.');
        }

        return new self($seconds);
    }

    /** Half of what is left until $deadline, at most $cap, so the sweeps after it keep their share. */
    public static function halfOfWhatIsLeft(\DateTimeImmutable $deadline, \DateTimeImmutable $now, int $cap): self
    {
        $remaining = $deadline->getTimestamp() - $now->getTimestamp();

        return self::seconds(max(0, min($cap, intdiv($remaining, 2))));
    }

    public function deadlineFrom(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return $start->modify(\sprintf('+%d seconds', $this->seconds));
    }
}
