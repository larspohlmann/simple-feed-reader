<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;

/** An entity manager a failed flush closed and nothing reopens: every flush throws. */
final class ClosedEntityManager extends EntityManagerDecorator
{
    private bool $open = true;

    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open && parent::isOpen();
    }

    public function flush(): void
    {
        throw new \RuntimeException('The entity manager is closed.');
    }
}
