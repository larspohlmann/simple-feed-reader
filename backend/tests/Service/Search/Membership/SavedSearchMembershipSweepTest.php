<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\TickingClock;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class SavedSearchMembershipSweepTest extends DbTestCase
{
    private const string NOW = '2026-09-22T10:00:00';

    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('sweep@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);
        $this->feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    public function testMatchesEverySettledEntryInsertsTheRowsAndAdvancesTheMark(): void
    {
        $search = $this->search('climate');
        $hit = $this->entry('a');
        $miss = $this->entry('b');
        $matcher = new RecordingSavedSearchMatcher([(int) $search->getId() => [(int) $hit->getId()]]);

        $report = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame(
            ['searchesSwept' => 1, 'entriesScanned' => 2, 'matchesInserted' => 1, 'caughtUp' => true],
            $report->toArray(),
        );
        self::assertSame([[$hit->getId(), $miss->getId()]], array_column($matcher->calls, 'candidates'));
        self::assertSame($miss->getId(), $this->reload($search)->matchedUpToEntryId());
        self::assertSame([$hit->getId()], $this->memberEntryIds($search));
    }

    public function testAnEntryYoungerThanTheSettleDelayWaitsForTheNextRun(): void
    {
        $search = $this->search('climate');
        $settled = $this->entry('a', createdAt: '2026-09-22T09:58:59');
        $young = $this->entry('b', createdAt: '2026-09-22T09:59:01');
        $matcher = new RecordingSavedSearchMatcher();

        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame([[$settled->getId()]], array_column($matcher->calls, 'candidates'));
        self::assertSame($settled->getId(), $this->reload($search)->matchedUpToEntryId());
        self::assertLessThan($young->getId(), $this->reload($search)->matchedUpToEntryId());
    }

    public function testSearchesAtTheSameMarkAreAskedTogetherInOneCall(): void
    {
        $first = $this->search('climate');
        $second = $this->search('rocket');
        $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher();

        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertCount(1, $matcher->calls);
        self::assertSame([$first->getId(), $second->getId()], $matcher->calls[0]['searchIds']);
    }

    public function testTheFurthestBehindGroupIsServedFirst(): void
    {
        $behind = $this->search('climate');
        $ahead = $this->search('rocket');
        $this->entry('a');
        $last = $this->entry('b');
        $ahead->advanceMatchedUpTo((int) $last->getId() - 1);
        $this->em->flush();
        $matcher = new RecordingSavedSearchMatcher();

        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame([$behind->getId()], $matcher->calls[0]['searchIds']);
        self::assertSame([$ahead->getId()], $matcher->calls[1]['searchIds']);
    }

    public function testASpentBudgetStopsBetweenChunksAndTheNextRunResumesAtTheMark(): void
    {
        $search = $this->search('climate');
        $ids = [];
        for ($i = 0; $i < SavedSearchMembershipSweep::CHUNK + 1; $i++) {
            $ids[] = (int) $this->entry('e' . $i)->getId();
        }
        // Every reading moves the clock 6 s: the deadline is read once, then
        // once per chunk, so a 10 s budget allows exactly one chunk.
        $clock = new TickingClock(new \DateTimeImmutable(self::NOW), 6);
        $matcher = new RecordingSavedSearchMatcher();

        $first = $this->sweep($matcher, $clock)->sweep(SweepBudget::seconds(10));

        self::assertFalse($first->caughtUp);
        self::assertSame(SavedSearchMembershipSweep::CHUNK, $first->entriesScanned);
        self::assertSame($ids[SavedSearchMembershipSweep::CHUNK - 1], $this->reload($search)->matchedUpToEntryId());

        $second = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertTrue($second->caughtUp);
        self::assertSame(1, $second->entriesScanned);
        self::assertSame(end($ids), $this->reload($search)->matchedUpToEntryId());
    }

    public function testAnUnavailableEngineLeavesTheMarkAloneInsertsNothingAndWarnsOnce(): void
    {
        $search = $this->search('climate');
        $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher([], new SearchEngineUnavailableException('down'));
        $logger = new RecordingLogger();

        $report = $this->sweep($matcher, logger: $logger)->sweep(SweepBudget::seconds(10));

        self::assertFalse($report->caughtUp);
        self::assertSame(0, $report->matchesInserted);
        self::assertSame(0, $this->reload($search)->matchedUpToEntryId());
        self::assertSame([], $this->memberEntryIds($search));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testAMatcherFailureAfterTheInsertLeavesNoRowAndNoMovedMark(): void
    {
        $search = $this->search('climate');
        $hit = $this->entry('a');
        $matcher = new class ((int) $search->getId(), (int) $hit->getId()) implements SavedSearchMatcher {
            public function __construct(private readonly int $searchId, private readonly int $hitId)
            {
            }

            public function matchingIds(array $searches, array $candidateEntryIds): array
            {
                return [$this->searchId => [$this->hitId]];
            }
        };
        /** @var ManagerRegistry $registry */
        $registry = self::getContainer()->get(ManagerRegistry::class);
        $memberships = new class ($registry) extends SavedSearchEntryMembershipRepository {
            public function insertMissing(int $savedSearchId, array $entryIds, \DateTimeImmutable $matchedAt): int
            {
                parent::insertMissing($savedSearchId, $entryIds, $matchedAt);

                throw new \RuntimeException('simulated failure after the insert');
            }
        };

        try {
            $this->sweep($matcher, memberships: $memberships)->sweep(SweepBudget::seconds(10));
            self::fail('The failure must propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->reload($search)->matchedUpToEntryId());
        self::assertSame([], $this->memberEntryIds($search));
    }

    public function testSweepOneWalksOnlyThatSearch(): void
    {
        $only = $this->search('climate');
        $other = $this->search('rocket');
        $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher();

        $report = $this->sweep($matcher)->sweepOne($only, SweepBudget::seconds(8));

        self::assertSame(1, $report->searchesSwept);
        self::assertSame([[$only->getId()]], array_column($matcher->calls, 'searchIds'));
        self::assertSame(0, $this->reload($other)->matchedUpToEntryId());
    }

    public function testNothingToDoIsOneQueryAndACaughtUpReport(): void
    {
        $matcher = new RecordingSavedSearchMatcher();

        $report = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertTrue($report->caughtUp);
        self::assertSame([], $matcher->calls);
    }

    private function sweep(
        SavedSearchMatcher $matcher,
        ?ClockInterface $clock = null,
        ?SavedSearchEntryMembershipRepository $memberships = null,
        ?RecordingLogger $logger = null,
    ): SavedSearchMembershipSweep {
        $searches = self::getContainer()->get(SavedSearchRepository::class);
        self::assertInstanceOf(SavedSearchRepository::class, $searches);
        $entries = self::getContainer()->get(EntryMembershipSweepRepository::class);
        self::assertInstanceOf(EntryMembershipSweepRepository::class, $entries);
        $membershipRepository = $memberships ?? self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchEntryMembershipRepository::class, $membershipRepository);

        return new SavedSearchMembershipSweep(
            $searches,
            $entries,
            $membershipRepository,
            $matcher,
            $this->em,
            $clock ?? new MockClock(self::NOW),
            $logger ?? new NullLogger(),
        );
    }

    private function search(string $term): SavedSearch
    {
        $search = new SavedSearch($this->user, $term, false);
        $this->em->persist($search);
        $this->em->flush();

        return $search;
    }

    private function entry(string $guid, string $createdAt = '2026-09-22T09:00:00'): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function reload(SavedSearch $search): SavedSearch
    {
        $this->em->clear();
        $reloaded = $this->em->find(SavedSearch::class, $search->getId());
        self::assertInstanceOf(SavedSearch::class, $reloaded);

        return $reloaded;
    }

    /** @return list<int> */
    private function memberEntryIds(SavedSearch $search): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM saved_search_entry WHERE saved_search_id = ? ORDER BY entry_id',
            [$search->getId()],
        );

        return array_map(intval(...), $ids);
    }
}
