<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Repository\EntryStateRepository;
use Psr\Clock\ClockInterface;

/** A copy that arrives after its post was read starts read for whoever read it. */
final readonly class CopyReadStateInheritor
{
    public function __construct(
        private EntryStateRepository $states,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<Entry> $created flushed, so each has its id
     *
     * @return int the read states inherited
     */
    public function inherit(array $created): int
    {
        $linkedIds = [];
        foreach ($created as $entry) {
            if (null !== $entry->getCanonicalItemId()) {
                $linkedIds[] = $entry->requireId();
            }
        }

        return $this->states->ensureReadWhereAnotherCopyIsRead($linkedIds, $this->clock->now());
    }
}
