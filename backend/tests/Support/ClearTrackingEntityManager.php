<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;

/** Records whether clear() was called, so a test proves a handler's per-firing cleanup ran instead of inferring it. */
final class ClearTrackingEntityManager extends EntityManagerDecorator
{
    private bool $wasCleared = false;

    public function clear(): void
    {
        $this->wasCleared = true;

        parent::clear();
    }

    public function wasCleared(): bool
    {
        return $this->wasCleared;
    }
}
