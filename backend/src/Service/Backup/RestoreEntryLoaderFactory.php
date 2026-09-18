<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Bundles the collaborators a fresh RestoreEntryLoader needs — six for the
 * loader itself, two more to build its RestoreFeedTargets — behind one
 * autowired service, so EntryPartRestorer's own constructor stays a handful
 * of parameters instead of carrying every one of these itself.
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
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
    ) {
    }

    public function create(User $user): RestoreEntryLoader
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
            new RestoreFeedTargets((int) $user->getId(), $this->subscriptions, $this->feeds, $this->entries),
            $user,
        );

        return $loader;
    }
}
