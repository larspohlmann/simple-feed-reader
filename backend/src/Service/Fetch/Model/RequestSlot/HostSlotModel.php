<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model\RequestSlot;

use App\Service\Fetch\Support\HostKey;

/** The host of whatever URL the attempt has reached, so a followed redirect counts against its new host. */
final readonly class HostSlotModel implements RequestSlotModelInterface
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
