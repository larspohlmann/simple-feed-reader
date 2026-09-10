<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\DatabaseSavedSearchUnreadMatches;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

/**
 * DatabaseSavedSearchUnreadMatches: the LIKE mark-read set behind the shared
 * interface. It must reproduce
 * SavedSearchEntryRepository::unreadMatchIdsForSavedSearches exactly, so
 * SavedSearchUnreadMatchesWithFallback can hand either path's result to the
 * mark-read flow unchanged.
 */
final class DatabaseSavedSearchUnreadMatchesTest extends DbTestCase
{
    private User $user;

    private Feed $feed;

    private int $unreadId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );

        $unread = $this->entry('a', 'Angular one', '2026-07-10T00:00:00Z');
        $read = $this->entry('b', 'Angular two', '2026-07-09T00:00:00Z');
        $this->hide($read);

        $this->em->flush();

        $this->unreadId = $unread->getId() ?? 0;
    }

    public function testReturnsTheRepositoryUnreadMatchSet(): void
    {
        $repository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $repository);

        $ids = (new DatabaseSavedSearchUnreadMatches($repository))->unreadMatchIdsUpTo(
            $this->user->getId() ?? 0,
            [new SavedSearchTerm(1, SearchTerms::fromInput('angular'))],
            new \DateTimeImmutable('2026-07-20T00:00:00Z'),
        );

        self::assertSame([$this->unreadId], $ids);
    }

    private function entry(string $guid, string $title, string $effectiveDate): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden(true);
        $this->em->persist($state);
    }
}
