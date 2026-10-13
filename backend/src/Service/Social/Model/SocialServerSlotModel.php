<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Support\HostKey;

/** A timeline read's Mastodon instance or Bluesky PDS, all its accounts together; an API ticket never redirects. */
final readonly class SocialServerSlotModel implements RequestSlotModelInterface
{
    public function __construct(private int $capacity)
    {
    }

    public function keyFor(string $url): string
    {
        return HostKey::forUrl($url);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
