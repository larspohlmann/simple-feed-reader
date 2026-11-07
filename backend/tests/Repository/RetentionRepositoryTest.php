<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RetentionRepositoryTest extends DbTestCase
{
    private const string FETCHED_AT = '2026-11-07 10:00:00';

    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('retention@example.test');
        $this->feed = new Feed('https://example.test/feed.xml');
        $this->entityManager->persist($this->feed);
    }

    public function testAnEntryFavouritedKeptOrSharedSinceItsIdWasReadSurvivesTheDelete(): void
    {
        $plain = $this->entry('plain');
        $read = $this->entry('read');
        $favourite = $this->entry('favourite');
        $kept = $this->entry('kept');
        $shared = $this->entry('shared');
        $readIds = $this->idsOf([$plain, $read, $favourite, $kept, $shared]);
        $now = new \DateTimeImmutable(self::FETCHED_AT);
        $this->state($read)->hide($now);
        $this->state($favourite)->markFavorite();
        $this->state($kept)->markKept();
        $this->entityManager->persist(new EntryShare($this->user, $shared, $now));
        $this->entityManager->flush();

        $deleted = $this->repository()->deleteUnprotectedEntries($readIds);

        self::assertEqualsCanonicalizing([$plain->requireId(), $read->requireId()], $deleted);
        self::assertEqualsCanonicalizing(['favourite', 'kept', 'shared'], $this->remainingGuids());
    }

    public function testMoreIdsThanOneQueryTakesAreAllDeleted(): void
    {
        $fetchedAt = new \DateTimeImmutable(self::FETCHED_AT);
        $entries = [];
        for ($index = 0; $index < 501; ++$index) {
            $entries[] = new Entry($this->feed, 'post-' . $index, null, 'Post', $fetchedAt, $fetchedAt);
            $this->entityManager->persist($entries[$index]);
        }
        $this->entityManager->flush();
        $ids = $this->idsOf($entries);

        self::assertEqualsCanonicalizing($ids, $this->repository()->deleteUnprotectedEntries($ids));
        self::assertSame([], $this->remainingGuids());
    }

    public function testNoIdsDeleteNothing(): void
    {
        self::assertSame([], $this->repository()->deleteUnprotectedEntries([]));
    }

    private function repository(): RetentionRepository
    {
        return new RetentionRepository($this->entityManager, new RowIds($this->entityManager));
    }

    private function entry(string $guid): Entry
    {
        $fetchedAt = new \DateTimeImmutable(self::FETCHED_AT);
        $entry = new Entry($this->feed, $guid, null, $guid, $fetchedAt, $fetchedAt);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function state(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $this->entityManager->persist($state);

        return $state;
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<int>
     */
    private function idsOf(array $entries): array
    {
        return array_map(static fn (Entry $entry): int => $entry->requireId(), $entries);
    }

    /** @return list<string> */
    private function remainingGuids(): array
    {
        $this->entityManager->clear();
        $entries = $this->entityManager->getRepository(Entry::class)->findAll();

        return array_map(static fn (Entry $entry): string => $entry->getGuid(), $entries);
    }
}
