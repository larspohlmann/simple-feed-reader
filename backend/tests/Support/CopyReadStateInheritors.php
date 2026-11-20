<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\EntryState;
use App\Repository\EntryStateRepository;
use App\Service\Ingestion\Ingest\CopyReadStateInheritor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** A CopyReadStateInheritor over the real entry states, for the tests that assemble an ingest path by hand. */
final class CopyReadStateInheritors
{
    private function __construct()
    {
    }

    public static function build(EntityManagerInterface $entityManager, ClockInterface $clock): CopyReadStateInheritor
    {
        /** @var EntryStateRepository $states */
        $states = $entityManager->getRepository(EntryState::class);

        return new CopyReadStateInheritor($states, $clock);
    }
}
