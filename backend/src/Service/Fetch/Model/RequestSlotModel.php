<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Fetch\Support\HostKey;

/** One cap a ticket holds while in flight: a named slot, or the host of whatever URL its attempt has reached. */
final readonly class RequestSlotModel
{
    private function __construct(private ?string $name, public int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
    }

    public static function named(string $name, int $capacity): self
    {
        return new self($name, $capacity);
    }

    public static function perHost(int $capacity): self
    {
        return new self(null, $capacity);
    }

    public function keyFor(string $url): string
    {
        return null === $this->name ? 'host:' . HostKey::forUrl($url) : 'slot:' . $this->name;
    }
}
