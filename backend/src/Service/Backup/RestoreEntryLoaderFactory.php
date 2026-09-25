<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\FeedRepository;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Builds the per-request RestoreEntryLoader, so EntryPartRestorer does not
 * carry the loader's collaborators itself.
 */
final readonly class RestoreEntryLoaderFactory
{
    public function __construct(
        private EntityManagerInterface $em,
        private EntryRepository $entries,
        private EntryStateRepository $entryStates,
        private EntryBatchInserter $inserter,
        private EntryIndexer $indexer,
        private ClockInterface $clock,
        private FeedRepository $feeds,
    ) {
    }

    /**
     * @param array<string, int> $feedIdsByUrl
     */
    public function create(User $user, array $feedIdsByUrl): RestoreEntryLoader
    {
        $loader = new RestoreEntryLoader(
            $this->em,
            $this->entries,
            $this->entryStates,
            $this->inserter,
            $this->indexer,
            $this->clock,
        );
        $loader->begin(
            new RestoreFeedTargets($user->requireId(), $feedIdsByUrl, $this->feeds, $this->entries),
            $user,
        );

        return $loader;
    }
}
