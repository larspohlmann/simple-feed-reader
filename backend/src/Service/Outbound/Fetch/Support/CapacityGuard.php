<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

final class CapacityGuard
{
    /** @throws \InvalidArgumentException when the capacity is below one */
    public static function atLeastOne(int $capacity, string $name): void
    {
        if ($capacity < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be at least 1, got %d.', $name, $capacity));
        }
    }

    private function __construct()
    {
    }
}
