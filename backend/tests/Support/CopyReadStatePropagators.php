<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\EntryReadMarkRepository;
use App\Service\Library\Reading\CopyReadStatePropagator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** A CopyReadStatePropagator over the real entry states, for the tests that assemble a write path by hand. */
final class CopyReadStatePropagators
{
    private function __construct()
    {
    }

    public static function build(EntityManagerInterface $entityManager, ClockInterface $clock): CopyReadStatePropagator
    {
        return new CopyReadStatePropagator(new EntryReadMarkRepository($entityManager), $clock);
    }
}
