<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;

/**
 * Makes the next flush() throw instead of reaching the wrapped, real
 * EntityManager -- so a test can prove a caller survives a flush() failure
 * without ever invoking the real UnitOfWork::commit(), which would close
 * the real EntityManager for the rest of the process (Doctrine's own
 * behaviour on any exception mid-flush) and poison every other service
 * sharing it. Everything except flush() delegates straight through
 * Doctrine's own EntityManagerDecorator base class, so a caller built with
 * this in place of the real EntityManagerInterface behaves identically
 * until the moment it flushes.
 */
final class FlushFailingEntityManager extends EntityManagerDecorator
{
    private bool $shouldThrowOnNextFlush = true;

    public function flush(): void
    {
        if ($this->shouldThrowOnNextFlush) {
            $this->shouldThrowOnNextFlush = false;

            throw new \RuntimeException('Simulated flush failure.');
        }

        parent::flush();
    }
}
