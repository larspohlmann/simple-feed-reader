<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Fetch\Support\HostKey;

/** A timeline read's Mastodon instance or Bluesky PDS, all its accounts together; an API ticket never redirects. */
final readonly class SocialServerSlotModel implements RequestSlotModelInterface
{
    private string $host;

    public function __construct(string $requestUrl, private int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
        $this->host = HostKey::forUrl($requestUrl);
    }

    public function keyFor(string $url): string
    {
        return $this->host;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
