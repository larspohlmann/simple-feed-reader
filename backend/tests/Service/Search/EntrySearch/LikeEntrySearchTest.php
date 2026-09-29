<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\EntrySearch;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\EntrySearch\LikeEntrySearch;
use App\Service\Search\Model\SearchTermsModel;
use App\Tests\DbTestCase;

/**
 * The seam an Elasticsearch implementation would replace. This test covers the
 * BEHAVIOUR only, so it builds the implementation directly.
 *
 * Nothing here guards the DI binding, and nothing can: Symfony autowires
 * EntrySearchInterface because exactly one service implements it, so removing
 * the explicit alias in services.yaml changes nothing until a second
 * implementation exists. The alias is kept because it states the binding and
 * makes that second implementation a one-line change rather than an ambiguity
 * error.
 */
final class LikeEntrySearchTest extends DbTestCase
{
    public function testFindsASubscribedEntryByTerm(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'guid',
            'https://example.com/guid',
            'Angular 20 ships',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);
        $search = new LikeEntrySearch($repository);

        $result = $search->search(new EntrySearchQuery(
            userId: $user->requireId(),
            terms: SearchTermsModel::fromInput('angular'),
        ));

        self::assertCount(1, $result->rows);
        self::assertSame('Angular 20 ships', $result->rows[0]->entry->getTitle());
        self::assertSame([], $result->matchedWords);
        // The database path's matchCount is the row count — nothing removes
        // rows after the query runs, unlike the indexed search's hydration
        // step — so EntrySearchResultModel must default it from count($rows)
        // rather than the caller having to say so.
        self::assertSame(1, $result->matchCount);
    }

    public function testUnreadSearchReturnsOnlyEffectivelyUnreadMatches(): void
    {
        $user = new User('unread-search@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $feed = new Feed('https://example.com/unread-search.xml');
        $this->entityManager->persist($feed);
        $subscription = new Subscription(
            $user,
            $feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->entityManager->persist($subscription);

        $belowWatermark = $this->matchingEntry($feed, 'below-watermark', '2026-07-05T00:00:00Z');
        $aboveWatermark = $this->matchingEntry($feed, 'above-watermark', '2026-07-15T00:00:00Z');
        $explicitUnread = $this->matchingEntry($feed, 'explicit-unread', '2026-07-05T00:00:00Z');
        $explicitRead = $this->matchingEntry($feed, 'explicit-read', '2026-07-15T00:00:00Z');

        $unreadState = new EntryState($user, $explicitUnread);
        $unreadState->markUnread();
        $readState = new EntryState($user, $explicitRead);
        $readState->hide(new \DateTimeImmutable('2026-07-01 09:00:00'));
        $this->entityManager->persist($unreadState);
        $this->entityManager->persist($readState);
        $this->entityManager->flush();

        $repository = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repository);
        $result = (new LikeEntrySearch($repository))->search(new EntrySearchQuery(
            userId: $user->requireId(),
            terms: SearchTermsModel::fromInput('angular'),
            unread: true,
        ));

        self::assertSame(
            [$aboveWatermark->getGuid(), $explicitUnread->getGuid()],
            array_map(static fn ($row): string => $row->entry->getGuid(), $result->rows),
        );
        self::assertNotContains($belowWatermark->getGuid(), array_map(
            static fn ($row): string => $row->entry->getGuid(),
            $result->rows,
        ));
        self::assertNotContains($explicitRead->getGuid(), array_map(
            static fn ($row): string => $row->entry->getGuid(),
            $result->rows,
        ));
    }

    private function matchingEntry(Feed $feed, string $guid, string $effectiveDate): Entry
    {
        $date = new \DateTimeImmutable($effectiveDate);
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            'Angular ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            $date,
        );
        $this->entityManager->persist($entry);

        return $entry;
    }
}
