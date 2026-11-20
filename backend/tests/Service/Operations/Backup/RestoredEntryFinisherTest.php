<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Backup;

use App\Service\Foundation\CanonicalItem\CanonicalItemLinker;
use App\Service\Library\Reading\CopyReadStatePropagator;
use App\Service\Library\Search\EntryIndexer;
use App\Service\Operations\Backup\RestoredEntryFinisher;
use App\Service\Operations\Backup\RestoreEntries\RestoreEntriesInterface;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class RestoredEntryFinisherTest extends DbTestCase
{
    public function testTheWalkStartsRightBeforeTheLowestCreatedEntry(): void
    {
        $walked = new \ArrayObject();

        $this->buildFinisher($this->buildRecordingEntries($walked))->finishCreated([9, 7, 12]);

        self::assertSame([6], $walked->getArrayCopy());
    }

    public function testNoCreatedEntryWalksNothing(): void
    {
        $walked = new \ArrayObject();

        $this->buildFinisher($this->buildRecordingEntries($walked))->finishCreated([]);

        self::assertSame([], $walked->getArrayCopy());
    }

    /** @param \ArrayObject<int, int> $walked the id each walk started after, filled as the finisher asks */
    private function buildRecordingEntries(\ArrayObject $walked): RestoreEntriesInterface
    {
        return new class ($walked) implements RestoreEntriesInterface {
            /** @param \ArrayObject<int, int> $startedAfter */
            public function __construct(private \ArrayObject $startedAfter)
            {
            }

            public function entriesAfterId(int $lastId, int $limit): array
            {
                $this->startedAfter->append($lastId);

                return [];
            }

            public function guidHashToIdMapForFeed(int $feedId): array
            {
                return [];
            }

            public function entryIdsByGuidHash(int $feedId, array $guidHashes): array
            {
                return [];
            }
        };
    }

    private function buildFinisher(RestoreEntriesInterface $entries): RestoredEntryFinisher
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $linker = self::getContainer()->get(CanonicalItemLinker::class);
        $indexer = self::getContainer()->get(EntryIndexer::class);
        $copyReadStates = self::getContainer()->get(CopyReadStatePropagator::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(CanonicalItemLinker::class, $linker);
        self::assertInstanceOf(EntryIndexer::class, $indexer);
        self::assertInstanceOf(CopyReadStatePropagator::class, $copyReadStates);

        return new RestoredEntryFinisher($entityManager, $entries, $linker, $indexer, $copyReadStates);
    }
}
