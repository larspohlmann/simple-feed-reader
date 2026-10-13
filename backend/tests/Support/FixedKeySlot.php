<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Support\CapacityGuard;

/** A slot whose key is fixed whatever URL its attempt has reached: an engine or slot test's named cap. */
final readonly class FixedKeySlot implements RequestSlotModelInterface
{
    public function __construct(private string $key, private int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
    }

    public function keyFor(string $url): string
    {
        return $this->key;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
