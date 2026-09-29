<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;

/**
 * unreadMatchingEntryIdsForUser is the set SearchMarkReadService flips; it
 * must reject a read match and a too-new match on its own, not merely rely
 * on the service's downstream state check to hide the difference.
 */
final class UnreadMatchingEntryIdsForUserTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('ids@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->entityManager->persist($this->feed);

        $this->entityManager->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->entityManager->flush();
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    private function entry(
        string $guid,
        string $title,
        string $effectiveDate = '2026-07-10T00:00:00Z',
    ): Entry {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<int> */
    private function ids(string $input, string $until = '2100-01-01T00:00:00Z'): array
    {
        return $this->repo()->unreadMatchingEntryIdsForUser(
            new EntrySearchQuery($this->user->requireId(), SearchTermsModel::fromInput($input)),
            new \DateTimeImmutable($until),
        );
    }

    public function testReturnsTheIntegerIdOfAnUnreadMatch(): void
    {
        $entry = $this->entry('a', 'Klima report');

        self::assertSame([$entry->getId()], $this->ids('klima'));
    }

    public function testExcludesAMatchThatIsAlreadyRead(): void
    {
        $entry = $this->entry('b', 'Klima update');
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        self::assertSame([], $this->ids('klima'));
    }

    public function testExcludesAMatchNewerThanUntil(): void
    {
        $this->entry('c', 'Klima forecast', '2026-08-01T00:00:00Z');

        self::assertSame([], $this->ids('klima', '2026-07-15T00:00:00Z'));
    }

    public function testExcludesANonMatch(): void
    {
        $this->entry('d', 'Cooking tips');

        self::assertSame([], $this->ids('klima'));
    }
}
