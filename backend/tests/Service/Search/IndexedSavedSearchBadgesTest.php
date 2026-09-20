<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\EntryCursor;
use App\Repository\DuplicateCollapseDql;
use App\Repository\EntryScopePredicates;
use App\Repository\FeedRepository;
use App\Repository\SavedSearchBadgeCandidateRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Service\Search\Index\IndexMatches;
use App\Service\Search\IndexedSavedSearchBadges;
use App\Service\Search\SavedSearchTerm;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;
use Doctrine\Persistence\ManagerRegistry;

/**
 * IndexedSavedSearchBadges: every saved search keyset-paged to exhaustion
 * against the engine (ids only), then narrowed to unread/subscribed/collapsed
 * in one database pass. Drives FakeMultiSearchReader directly — no running
 * Meilisearch — mirroring IndexedSavedSearchUnreadMatchesTest.
 */
final class IndexedSavedSearchBadgesTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

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

        $this->em->flush();
    }

    private function entry(string $guid, string $effectiveDate = '2026-07-10T00:00:00Z', ?Feed $feed = null): Entry
    {
        $entry = new Entry(
            $feed ?? $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function markRead(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();
    }

    private function badges(FakeMultiSearchReader $reader): IndexedSavedSearchBadges
    {
        $container = self::getContainer();
        /** @var FeedRepository $feedRepository */
        $feedRepository = $container->get(FeedRepository::class);
        /** @var ManagerRegistry $registry */
        $registry = $container->get(ManagerRegistry::class);
        /** @var EntryScopePredicates $scope */
        $scope = $container->get(EntryScopePredicates::class);
        /** @var DuplicateCollapseDql $collapse */
        $collapse = $container->get(DuplicateCollapseDql::class);

        return new IndexedSavedSearchBadges(
            $reader,
            $feedRepository,
            new SavedSearchBadgeCandidateRepository($registry, $scope, $collapse),
        );
    }

    /**
     * @param list<SavedSearchTerm> $searches
     *
     * @return array<int, list<int>>
     */
    private function badgesFor(FakeMultiSearchReader $reader, array $searches): array
    {
        return $this->badges($reader)->unreadMatchIdsBySavedSearch($this->user->getId() ?? 0, $searches);
    }

    private function term(int $id, string $word = 'post'): SavedSearchTerm
    {
        return new SavedSearchTerm($id, SearchTerms::fromInput($word));
    }

    public function testEmptyFeedsAnswersWithoutAskingTheEngine(): void
    {
        $userWithNoSubscription = new User('lonely@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($userWithNoSubscription);
        $this->em->flush();

        $reader = new FakeMultiSearchReader();
        $result = $this->badges($reader)->unreadMatchIdsBySavedSearch(
            $userWithNoSubscription->getId() ?? 0,
            [$this->term(1)],
        );

        self::assertSame([], $result);
        self::assertSame([], $reader->receivedRounds);
    }

    public function testEmptySearchesAnswersWithoutAskingTheEngine(): void
    {
        $reader = new FakeMultiSearchReader();

        self::assertSame([], $this->badgesFor($reader, []));
        self::assertSame([], $reader->receivedRounds);
    }

    public function testASingleRoundPutsEveryIdUnderItsOwnSearch(): void
    {
        $a = $this->entry('a');
        $b = $this->entry('b');

        $reader = new FakeMultiSearchReader([
            [new IndexMatches([$a->getId() ?? 0], []), new IndexMatches([$b->getId() ?? 0], [])],
        ]);

        $result = $this->badgesFor($reader, [$this->term(10), $this->term(20)]);

        self::assertSame([10 => [$a->getId()], 20 => [$b->getId()]], $result);
    }

    public function testAnEntryMatchingTwoSearchesLandsInBoth(): void
    {
        $both = $this->entry('a');

        $reader = new FakeMultiSearchReader([
            [new IndexMatches([$both->getId() ?? 0], []), new IndexMatches([$both->getId() ?? 0], [])],
        ]);

        $result = $this->badgesFor($reader, [$this->term(10), $this->term(20)]);

        self::assertSame([10 => [$both->getId()], 20 => [$both->getId()]], $result);
    }

    public function testReadAndMarkedReadEntriesAreDropped(): void
    {
        $unread = $this->entry('a', '2026-07-12T00:00:00Z');
        $read = $this->entry('b', '2026-07-11T00:00:00Z');
        $this->markRead($read);

        $reader = new FakeMultiSearchReader([
            [new IndexMatches([$unread->getId() ?? 0, $read->getId() ?? 0], [])],
        ]);

        self::assertSame([10 => [$unread->getId()]], $this->badgesFor($reader, [$this->term(10)]));
    }

    public function testAnUnsubscribedFeedsEntryIsDroppedEvenIfTheEngineReturnedIt(): void
    {
        $otherFeed = new Feed('https://example.com/other.xml');
        $otherFeed->setTitle('Other');
        $this->em->persist($otherFeed);
        $this->em->flush();

        $foreign = $this->entry('a', '2026-07-10T00:00:00Z', $otherFeed);

        $reader = new FakeMultiSearchReader([[new IndexMatches([$foreign->getId() ?? 0], [])]]);

        self::assertSame([], $this->badgesFor($reader, [$this->term(10)]));
    }

    public function testASearchWithZeroUnreadMatchesIsOmitted(): void
    {
        $read = $this->entry('a');
        $this->markRead($read);

        $reader = new FakeMultiSearchReader([[new IndexMatches([$read->getId() ?? 0], [])]]);

        self::assertSame([], $this->badgesFor($reader, [$this->term(10)]));
    }

    public function testASearchNeedingThreeRoundsWhileAnEarlierOneExhaustsAfterOne(): void
    {
        // ENGINE_PAGE = 1000: search 20 sits FIRST and exhausts after round
        // one (a partial page); search 10 sits SECOND and needs two more
        // rounds. The exhausted search coming first is deliberate: it proves
        // round two's remaining search is still matched against the right
        // engine result and not dropped, even though it is no longer at
        // position 0 in the original search list.
        $real1 = $this->entry('r1', '2026-07-12T00:00:00Z');
        $real2 = $this->entry('r2', '2026-07-11T00:00:00Z');
        $real3 = $this->entry('r3', '2026-07-10T00:00:00Z');
        $exhaustedMatch = $this->entry('x1', '2026-07-13T00:00:00Z');

        $ghostsRound1 = range(900_000, 900_000 + 998);
        $ghostsRound2 = range(910_000, 910_000 + 998);

        // The real id sits LAST in each full page so it is the round's
        // boundary — effectiveDatesByIds only resolves persisted entries.
        $round1Search20 = new IndexMatches([$exhaustedMatch->getId() ?? 0], []);
        $round1Search10 = new IndexMatches([...$ghostsRound1, $real1->getId() ?? 0], []);
        $round2Search10 = new IndexMatches([...$ghostsRound2, $real2->getId() ?? 0], []);
        $round3Search10 = new IndexMatches([$real3->getId() ?? 0], []);

        $reader = new FakeMultiSearchReader([
            [$round1Search20, $round1Search10],
            [$round2Search10],
            [$round3Search10],
        ]);

        $result = $this->badgesFor($reader, [$this->term(20), $this->term(10)]);

        self::assertSame(
            [20 => [$exhaustedMatch->getId()], 10 => [$real1->getId(), $real2->getId(), $real3->getId()]],
            $result,
        );
        self::assertCount(3, $reader->receivedRounds);
        self::assertCount(2, $reader->receivedRounds[0]);
        self::assertCount(1, $reader->receivedRounds[1], 'The exhausted search must not be asked again.');
        self::assertCount(1, $reader->receivedRounds[2]);
    }

    public function testTwoSearchesBothFullPagesInOneRoundBothGetABoundaryAndACursor(): void
    {
        $lastA = $this->entry('a-last', '2026-07-09T00:00:00Z');
        $lastB = $this->entry('b-last', '2026-07-08T00:00:00Z');
        $ghostsA = range(900_000, 900_000 + 998);
        $ghostsB = range(910_000, 910_000 + 998);

        $round1SearchA = new IndexMatches([...$ghostsA, $lastA->getId() ?? 0], []);
        $round1SearchB = new IndexMatches([...$ghostsB, $lastB->getId() ?? 0], []);
        $round2SearchA = new IndexMatches([], []);
        $round2SearchB = new IndexMatches([], []);

        $reader = new FakeMultiSearchReader([
            [$round1SearchA, $round1SearchB],
            [$round2SearchA, $round2SearchB],
        ]);

        $this->badgesFor($reader, [$this->term(10), $this->term(20)]);

        self::assertCount(2, $reader->receivedRounds);
        self::assertCount(2, $reader->receivedRounds[1], 'Both searches must still be active for round two.');
        $cursorA = $reader->receivedRounds[1][0]->cursor;
        $cursorB = $reader->receivedRounds[1][1]->cursor;
        self::assertInstanceOf(EntryCursor::class, $cursorA);
        self::assertInstanceOf(EntryCursor::class, $cursorB);
        self::assertSame($lastA->getId(), $cursorA->id);
        self::assertSame($lastB->getId(), $cursorB->id);
    }

    public function testASearchWithNoUnreadMatchesDoesNotSuppressALaterSearchsMatches(): void
    {
        $read = $this->entry('a');
        $this->markRead($read);
        $unread = $this->entry('b');

        $reader = new FakeMultiSearchReader([
            [new IndexMatches([$read->getId() ?? 0], []), new IndexMatches([$unread->getId() ?? 0], [])],
        ]);

        self::assertSame([20 => [$unread->getId()]], $this->badgesFor($reader, [$this->term(10), $this->term(20)]));
    }

    public function testTheSecondRoundsCursorCarriesTheFirstRoundsLastIdAndItsEffectiveDate(): void
    {
        $lastOfRound1 = $this->entry('last', '2026-07-09T00:00:00Z');
        $ghosts = range(900_000, 900_000 + 998);
        $round1 = new IndexMatches([...$ghosts, $lastOfRound1->getId() ?? 0], []);
        $round2 = new IndexMatches([], []);

        $reader = new FakeMultiSearchReader([[$round1], [$round2]]);

        $this->badgesFor($reader, [$this->term(10)]);

        self::assertCount(2, $reader->receivedRounds);
        $cursorOfRound2 = $reader->receivedRounds[1][0]->cursor;
        self::assertInstanceOf(EntryCursor::class, $cursorOfRound2);
        self::assertSame($lastOfRound1->getId(), $cursorOfRound2->id);
        self::assertEquals(new \DateTimeImmutable('2026-07-09T00:00:00Z'), $cursorOfRound2->sortInstant);
    }

    /**
     * The engine's own matching is faked, so parity is proven at the point the
     * two paths share behaviour: given the SAME candidate ids, both narrow
     * them to unread/subscribed/collapsed identically, because
     * IndexedSavedSearchBadges delegates that step to the exact same
     * SavedSearchBadgeCandidateRepository query the database path's own
     * unread scan reduces to. Here that is proven by feeding the engine path
     * every entry id a plain-title LIKE match would also find, and asserting
     * the two answers agree.
     */
    public function testParityWithTheDatabasePathForAPlainSearch(): void
    {
        $unread = $this->postEntry('a', '2026-07-10T00:00:00Z');
        $read = $this->postEntry('b', '2026-07-09T00:00:00Z');
        $this->markRead($read);

        $reader = new FakeMultiSearchReader([
            [new IndexMatches([$unread->getId() ?? 0, $read->getId() ?? 0], [])],
        ]);

        $engineResult = $this->badgesFor($reader, [$this->term(10)]);

        $databaseRepository = self::getContainer()->get(SavedSearchEntryRepository::class);
        self::assertInstanceOf(SavedSearchEntryRepository::class, $databaseRepository);
        $databaseResult = $databaseRepository->unreadMatchIdsBySavedSearch(
            $this->user->getId() ?? 0,
            [$this->term(10)],
        );

        self::assertSame($databaseResult, $engineResult);
    }

    private function postEntry(string $guid, string $effectiveDate): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'A post about ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
