<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Pass 2 of the restore: the second read of the same bytes, this time writing
 * rows. It assumes AccountReset has just run — nothing here removes anything.
 *
 * The service itself is stateless and shared. All the per-run state a load
 * needs — the tag and feed maps, the feed ids, the guid hash maps, the entry
 * buffer — lives on the two collaborators built here and discarded with the
 * call, which is the shape phptramp asks for instead of threading those maps
 * through a chain of parameters.
 */
final readonly class RestoreLoader
{
    public function __construct(
        private EntityManagerInterface $em,
        private BackupReader $reader,
        private FeedRepository $feeds,
        private EntryRepository $entries,
        private EntryBatchInserter $inserter,
        private EntryIndexer $indexer,
        private ClockInterface $clock,
    ) {
    }

    public function load(User $user, string $gzipBytes): RestoreResult
    {
        $entryLoader = new RestoreEntryLoader(
            $this->em,
            $this->entries,
            $this->inserter,
            $this->indexer,
            $this->clock,
        );
        $pass = new RestoreLoadPass(
            $this->em,
            $this->feeds,
            $this->entries,
            $entryLoader,
        );

        return $pass->run($user, $this->reader->read($gzipBytes));
    }
}
