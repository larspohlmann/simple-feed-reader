<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;

/**
 * Records whether clear() was called, so a test can prove a handler's
 * per-firing cleanup actually ran instead of only inferring it from
 * unrelated side effects. Everything else delegates straight through
 * Doctrine's own EntityManagerDecorator base class.
 */
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
