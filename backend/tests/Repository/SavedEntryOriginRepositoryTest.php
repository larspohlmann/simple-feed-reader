<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryOrigin;
use App\Entity\Feed;
use App\Entity\SavedEntryOrigin;
use App\Repository\SavedEntryOriginRepository;
use App\Tests\DbTestCase;

final class SavedEntryOriginRepositoryTest extends DbTestCase
{
    public function testOriginsAreReadByEntryAndAnEntryWithoutOneIsAbsent(): void
    {
        $feed = new Feed('saved:1');
        $this->entityManager->persist($feed);
        $withOrigin = $this->entry($feed, 'a');
        $without = $this->entry($feed, 'b');
        $this->entityManager->persist(
            new SavedEntryOrigin($withOrigin, new EntryOrigin('Heise', 'https://heise.de/icon.png')),
        );
        $this->entityManager->flush();

        $origins = $this->repository()->originsByEntryIds([$withOrigin->requireId(), $without->requireId()]);

        self::assertEquals(
            [$withOrigin->requireId() => new EntryOrigin('Heise', 'https://heise.de/icon.png')],
            $origins,
        );
        self::assertSame([], $this->repository()->originsByEntryIds([]));
    }

    public function testTheOriginGoesWithItsEntry(): void
    {
        $feed = new Feed('saved:2');
        $this->entityManager->persist($feed);
        $entry = $this->entry($feed, 'gone');
        $this->entityManager->persist(new SavedEntryOrigin($entry, new EntryOrigin('Gone', null)));
        $this->entityManager->flush();
        $entryId = $entry->requireId();

        $this->entityManager->createQuery(sprintf('DELETE FROM %s e WHERE e.id = :id', Entry::class))
            ->setParameter('id', $entryId)
            ->execute();
        $this->entityManager->clear();

        self::assertSame([], $this->repository()->originsByEntryIds([$entryId]));
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $at = new \DateTimeImmutable('2026-10-17 10:00:00');
        $entry = new Entry($feed, $guid, 'https://example.com/' . $guid, 'Title', $at, $at);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function repository(): SavedEntryOriginRepository
    {
        /** @var SavedEntryOriginRepository $repository */
        $repository = $this->entityManager->getRepository(SavedEntryOrigin::class);

        return $repository;
    }
}
