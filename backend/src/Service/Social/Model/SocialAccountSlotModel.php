<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Support\CapacityGuard;

/** A timeline read's account: Bluesky and Mastodon ration requests per account. */
final readonly class SocialAccountSlotModel implements RequestSlotModelInterface
{
    public function __construct(private int $accountId, private int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
    }

    public function keyFor(string $url): string
    {
        return (string) $this->accountId;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
