<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Factory;

use App\Entity\User;
use App\Repository\EntryBatchInserter;
use App\Repository\EntryRepository;
use App\Repository\EntryShareRepository;
use App\Repository\EntryStateRepository;
use App\Repository\FeedRepository;
use App\Service\Operations\Backup\Pass\RestoreDestination;
use App\Service\Operations\Backup\Pass\RestoreEntryLoader;
use App\Service\Operations\Backup\Pass\RestoreFeedTargets;
use App\Service\Library\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the per-request RestoreEntryLoader, so EntryPartRestorer does not
 * carry the loader's collaborators itself.
 */
final readonly class RestoreEntryLoaderFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryRepository $entries,
        private EntryStateRepository $entryStates,
        private EntryShareRepository $entryShares,
        private EntryBatchInserter $inserter,
        private EntryIndexer $indexer,
        private RestoredEntryStateFactory $stateFactory,
        private FeedRepository $feeds,
    ) {
    }

    /**
     * @param array<string, int> $feedIdsByUrl
     */
    public function create(User $user, array $feedIdsByUrl): RestoreEntryLoader
    {
        return new RestoreEntryLoader(
            $this->entityManager,
            $this->entries,
            $this->entryStates,
            $this->entryShares,
            $this->inserter,
            $this->indexer,
            $this->stateFactory,
            new RestoreDestination(
                $user,
                new RestoreFeedTargets($user->requireId(), $feedIdsByUrl, $this->feeds, $this->entries),
            ),
        );
    }
}
