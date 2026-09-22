<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Membership;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\Membership\SavedSearchMembershipWriter;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use App\Tests\DbTestCase;
use App\Tests\Support\StoredMark;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\TickingClock;
use Psr\Clock\ClockInterface;
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
        self::assertSame($miss->getId(), $this->markOf($search));
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
        self::assertSame($settled->getId(), $this->markOf($search));
        self::assertLessThan($young->getId(), $this->markOf($search));
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

    public function testAGroupWalksToTheNextMarkThenJoinsThatGroup(): void
    {
        $behind = $this->search('climate');
        $ahead = $this->search('rocket');
        $first = $this->entry('a');
        $second = $this->entry('b');
        $third = $this->entry('c');
        $ahead->advanceMatchedUpTo((int) $second->getId());
        $this->em->flush();
        $matcher = new RecordingSavedSearchMatcher();

        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame([
            ['searchIds' => [$behind->getId()], 'candidates' => [$first->getId(), $second->getId()]],
            ['searchIds' => [$behind->getId(), $ahead->getId()], 'candidates' => [$third->getId()]],
        ], $matcher->calls);
        self::assertSame($third->getId(), $this->markOf($behind));
        self::assertSame($third->getId(), $this->markOf($ahead));
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
        self::assertSame($ids[SavedSearchMembershipSweep::CHUNK - 1], $this->markOf($search));

        $second = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertTrue($second->caughtUp);
        self::assertSame(1, $second->entriesScanned);
        self::assertSame(end($ids), $this->markOf($search));
    }

    public function testEntriesScannedSumsAcrossEveryChunkInOneRun(): void
    {
        $this->search('climate');
        for ($i = 0; $i < SavedSearchMembershipSweep::CHUNK + 1; $i++) {
            $this->entry('e' . $i);
        }
        $matcher = new RecordingSavedSearchMatcher();

        $report = $this->sweep($matcher)->sweep(SweepBudget::seconds(1000));

        self::assertTrue($report->caughtUp);
        self::assertCount(2, $matcher->calls);
        self::assertSame(SavedSearchMembershipSweep::CHUNK + 1, $report->entriesScanned);
    }

    public function testMatchesInsertedSumsAcrossEveryMemberOfTheGroup(): void
    {
        $first = $this->search('climate');
        $second = $this->search('rocket');
        $hitForFirst = $this->entry('a');
        $firstHitForSecond = $this->entry('b');
        $secondHitForSecond = $this->entry('c');
        $matcher = new RecordingSavedSearchMatcher([
            (int) $first->getId() => [(int) $hitForFirst->getId()],
            (int) $second->getId() => [(int) $firstHitForSecond->getId(), (int) $secondHitForSecond->getId()],
        ]);

        $report = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertSame(3, $report->matchesInserted);
    }

    public function testABudgetExhaustedExactlyAtTheDeadlineStopsBeforeTheChunk(): void
    {
        $search = $this->search('climate');
        $this->entry('a');
        $this->entry('b');
        // The ceiling read (elapsed 0s) and the deadline read (elapsed 6s) set
        // the deadline at start+12s; the first in-loop reading (elapsed 12s)
        // lands exactly on it, so a 6 s budget must stop before any chunk runs.
        $clock = new TickingClock(new \DateTimeImmutable(self::NOW), 6);
        $matcher = new RecordingSavedSearchMatcher();

        $report = $this->sweep($matcher, $clock)->sweep(SweepBudget::seconds(6));

        self::assertFalse($report->caughtUp);
        self::assertSame([], $matcher->calls);
        self::assertSame(0, $report->entriesScanned);
        self::assertSame(0, $this->markOf($search));
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
        self::assertSame(0, $this->markOf($search));
        self::assertSame([], $this->memberEntryIds($search));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testAFailureAfterTheInsertRollsBackTheRowsKeepsTheMarkAndStopsTheRun(): void
    {
        $search = $this->search('climate');
        $hit = $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher([(int) $search->getId() => [(int) $hit->getId()]]);
        $realWriter = self::getContainer()->get(SavedSearchEntryMembershipRepository::class);
        self::assertInstanceOf(SavedSearchMembershipWriter::class, $realWriter);
        $failingAfterInsert = new class ($realWriter) implements SavedSearchMembershipWriter {
            public function __construct(private readonly SavedSearchMembershipWriter $inner)
            {
            }

            public function insertMissing(array $entryIdsBySavedSearchId, \DateTimeImmutable $matchedAt): int
            {
                $this->inner->insertMissing($entryIdsBySavedSearchId, $matchedAt);

                throw new \RuntimeException('simulated failure after the insert');
            }
        };
        $logger = new RecordingLogger();

        $report = $this->sweep($matcher, memberships: $failingAfterInsert, logger: $logger)
            ->sweep(SweepBudget::seconds(10));

        self::assertFalse($report->caughtUp);
        self::assertSame(0, $report->matchesInserted);
        self::assertSame(0, $this->markOf($search));
        self::assertSame([], $this->memberEntryIds($search));
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
    }

    public function testASecondRunOverTheSameChunkInsertsNothingAndDoesNotFail(): void
    {
        $search = $this->search('climate');
        $hit = $this->entry('a');
        $matcher = new RecordingSavedSearchMatcher([(int) $search->getId() => [(int) $hit->getId()]]);
        $this->sweep($matcher)->sweep(SweepBudget::seconds(10));
        $this->em->getConnection()->executeStatement('UPDATE saved_search SET matched_up_to_entry_id = 0');

        $report = $this->sweep($matcher)->sweep(SweepBudget::seconds(10));

        self::assertTrue($report->caughtUp);
        self::assertSame(0, $report->matchesInserted);
        self::assertSame([$hit->getId()], $this->memberEntryIds($search));
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
        self::assertSame(0, $this->markOf($other));
    }

    public function testSweepOneAlreadyAtTheCeilingDoesNothing(): void
    {
        $search = $this->search('climate');
        $entry = $this->entry('a');
        $search->advanceMatchedUpTo((int) $entry->getId());
        $this->em->flush();
        $matcher = new RecordingSavedSearchMatcher();

        $report = $this->sweep($matcher)->sweepOne($search, SweepBudget::seconds(8));

        self::assertSame(0, $report->searchesSwept);
        self::assertSame([], $matcher->calls);
        self::assertTrue($report->caughtUp);
        self::assertSame($entry->getId(), $this->markOf($search));
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
        ?SavedSearchMembershipWriter $memberships = null,
        ?RecordingLogger $logger = null,
    ): SavedSearchMembershipSweep {
        return MembershipSweepFactory::fromContainer(
            self::getContainer(),
            $this->em,
            $matcher,
            $clock ?? new MockClock(self::NOW),
            $logger,
            $memberships,
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

    private function markOf(SavedSearch $search): int
    {
        return StoredMark::of($this->em, $search);
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
