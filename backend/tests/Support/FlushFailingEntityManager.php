<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes one chosen flush() throw without reaching the real EntityManager: a real mid-flush failure closes it,
 * which would poison every other service sharing it for the rest of the process. Everything else delegates.
 */
final class FlushFailingEntityManager extends EntityManagerDecorator
{
    private int $flushes = 0;

    public function __construct(
        EntityManagerInterface $wrapped,
        private readonly int $failingFlush = 1,
        private readonly \Throwable $thrown = new \RuntimeException('Simulated flush failure.'),
    ) {
        parent::__construct($wrapped);
    }

    public function flush(): void
    {
        $this->flushes++;
        if ($this->flushes === $this->failingFlush) {
            throw $this->thrown;
        }

        parent::flush();
    }
}
