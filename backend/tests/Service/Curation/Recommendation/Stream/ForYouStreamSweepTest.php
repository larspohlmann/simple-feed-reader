<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\Entry;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Entity\NetworkExclusion;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\ForYouStreamMembershipRepository;
use App\Repository\ForYouStreamSourceRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\Factory\EntryStemmingFactory;
use App\Service\Curation\Recommendation\Stream\ForYouStreamChunkMatcher;
use App\Service\Curation\Recommendation\Stream\ForYouStreamMembershipWriter\ForYouStreamMembershipWriterInterface;
use App\Service\Curation\Recommendation\Stream\ForYouStreamScorer;
use App\Service\Curation\Recommendation\Stream\ForYouStreamSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Library\Search\Membership\MarkGroupWalker;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\SettledCeiling;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StemmingWiring;
use App\Tests\Support\TickingClock;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class ForYouStreamSweepTest extends DbTestCase
{
    private const string NOW = '2026-11-27T10:00:00';
    private const string SETTLED = '2026-11-27 09:00:00';
    private const string RECENT = '2026-11-27 08:00:00';

    private Feed $feed;
    private int $nextEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->feed = new Feed('https://example.com/stream.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testAMatchingEntryIsStoredWithItsScoreAndStemsAndTheMarkAdvances(): void
    {
        $user = $this->subscriber('hit@example.com');
        $this->term($user, 'solar', 2.0);
        $hit = $this->entry('Solar panels');

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(
            ['usersSwept' => 1, 'entriesScanned' => 1, 'matchesInserted' => 1, 'caughtUp' => true],
            $report->toLogContext(),
        );
        self::assertSame($hit->requireId(), $this->readMark($user));
        [$row] = $this->readRows($user);
        self::assertSame($hit->requireId(), $row['entryId']);
        self::assertEqualsWithDelta(2.0, $row['score'], 1e-6);
        self::assertSame($this->stem('solar'), $row['stems']);
        self::assertSame('2026-11-27 10:00:00', $row['matchedAt']);
    }

    public function testAnEntryBelowTheThresholdIsPassedButNotStored(): void
    {
        $user = $this->subscriber('miss@example.com');
        $this->term($user, 'solar', 1.0);
        $entry = $this->entry('Solar panels');

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(0, $report->matchesInserted);
        self::assertSame($entry->requireId(), $this->readMark($user));
        self::assertSame([], $this->readRows($user));
    }

    public function testAnEntryInAFeedOutsideForYouIsNotACandidate(): void
    {
        $user = $this->subscriber('outside@example.com');
        $this->term($user, 'solar', 2.0);
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->changeInclusions(new InclusionChange(forYou: false));
        $entry = $this->entry('Solar panels');
        $this->entityManager->flush();

        $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame([], $this->readRows($user));
        self::assertSame($entry->requireId(), $this->readMark($user));
    }

    public function testAnEntryInAnUnsubscribedFeedIsNotACandidate(): void
    {
        $user = $this->subscriber('unsubscribed@example.com');
        $this->term($user, 'solar', 2.0);
        $otherFeed = new Feed('https://example.com/unsubscribed.xml');
        $this->entityManager->persist($otherFeed);
        $this->entityManager->flush();
        $entry = $this->entry('Solar panels', feed: $otherFeed);

        $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame([], $this->readRows($user));
        self::assertSame($entry->requireId(), $this->readMark($user));
    }

    public function testAnInactiveAccountIsNotSweptAndKeepsItsMark(): void
    {
        $user = $this->subscriber('suspended@example.com');
        $user->suspend();
        $this->entityManager->flush();
        $this->term($user, 'solar', 2.0);
        $this->entry('Solar panels');

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(0, $report->usersSwept);
        self::assertSame([], $this->readRows($user));
        self::assertSame(0, $this->readMark($user));
    }

    public function testAUserWhoExcludedTheFeedsNetworkGetsNoRowWhileTheOtherAtTheSameMarkDoes(): void
    {
        $excluding = $this->subscriber('excluding@example.com');
        $keeping = $this->subscriber('keeping@example.com');
        $this->term($excluding, 'solar', 2.0);
        $this->term($keeping, 'solar', 2.0);
        $bluesky = new Feed('https://bsky.app/profile/alice.bsky.social/rss');
        $this->entityManager->persist($bluesky);
        foreach ([$excluding, $keeping] as $user) {
            $this->entityManager->persist(new Subscription($user, $bluesky, new \DateTimeImmutable('2026-07-01')));
        }
        $this->entityManager->persist(
            new NetworkExclusion($excluding, SocialNetwork::Bluesky, InclusionSurface::ForYou),
        );
        $this->entry('Solar panels', feed: $bluesky);

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(1, $report->matchesInserted);
        self::assertSame([], $this->readRows($excluding));
        self::assertCount(1, $this->readRows($keeping));
    }

    public function testAnEntryPublishedMoreThan48HoursBeforeTheSweepIsSkippedAndPassed(): void
    {
        $user = $this->subscriber('stale@example.com');
        $this->term($user, 'solar', 2.0);
        $stale = $this->entry('Solar panels', effectiveAt: '2026-11-25 09:59:59');
        $fresh = $this->entry('Solar roofs', effectiveAt: '2026-11-25 10:00:00');

        $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame([$fresh->requireId()], $this->readEntryIds($user));
        self::assertSame($fresh->requireId(), $this->readMark($user));
        self::assertLessThan($fresh->requireId(), $stale->requireId());
    }

    public function testAnEntryYoungerThanTheSettleDelayWaitsForTheNextRun(): void
    {
        $user = $this->subscriber('young@example.com');
        $this->term($user, 'solar', 2.0);
        $settled = $this->entry('Solar panels', createdAt: '2026-11-27 09:58:59');
        $young = $this->entry('Solar roofs', createdAt: '2026-11-27 09:59:01');

        $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame($settled->requireId(), $this->readMark($user));
        self::assertLessThan($young->requireId(), $this->readMark($user));
        self::assertCount(1, $this->readRows($user));
    }

    public function testAnEntryWithoutALanguageMatchesThroughTheCutForm(): void
    {
        $user = $this->subscriber('cut@example.com');
        $this->entityManager->persist(new InterestTerm($user, WordStems::cut('solar'), null, 2.0));
        $this->entityManager->flush();
        $this->entry('Solar panels', language: null);

        $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertCount(1, $this->readRows($user));
    }

    public function testTwoUsersAtOneMarkShareOneStemmingOfEachEntry(): void
    {
        $first = $this->subscriber('share-a@example.com');
        $second = $this->subscriber('share-b@example.com');
        $this->term($first, 'solar', 2.0);
        $this->term($second, 'solar', 3.0);
        $this->entry('Solar panels');

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(2, $report->usersSwept);
        self::assertSame(1, $report->entriesScanned);
        self::assertSame(2, $report->matchesInserted);
        self::assertCount(1, $this->readRows($first));
        self::assertCount(1, $this->readRows($second));
    }

    public function testAUserBehindCatchesUpToTheOthersMarkThenJoinsThem(): void
    {
        $behind = $this->subscriber('behind@example.com');
        $ahead = $this->subscriber('ahead@example.com', mark: null);
        $this->term($behind, 'solar', 2.0);
        $this->term($ahead, 'solar', 2.0);
        $first = $this->entry('Solar panels');
        $second = $this->entry('Solar roofs');
        $third = $this->entry('Solar farms');
        $this->setMark($ahead, $second->requireId());

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(3, $report->entriesScanned);
        self::assertSame(4, $report->matchesInserted);
        self::assertSame($third->requireId(), $this->readMark($behind));
        self::assertSame($third->requireId(), $this->readMark($ahead));
        self::assertSame(
            [$first->requireId(), $second->requireId(), $third->requireId()],
            $this->readEntryIds($behind),
        );
        self::assertSame(
            [$third->requireId()],
            $this->readEntryIds($ahead),
        );
    }

    public function testTheBudgetStopsTheRunShort(): void
    {
        $user = $this->subscriber('budget@example.com');
        $ids = array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->entries(MarkGroupWalker::CHUNK + 1),
        );
        $clock = new TickingClock(new \DateTimeImmutable(self::NOW), 6);

        $first = $this->sweep($clock)->sweep(SweepBudgetModel::seconds(10));

        self::assertFalse($first->caughtUp);
        self::assertSame(MarkGroupWalker::CHUNK, $first->entriesScanned);
        self::assertSame($ids[MarkGroupWalker::CHUNK - 1], $this->readMark($user));
        $this->entityManager->clear();

        $second = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertTrue($second->caughtUp);
        self::assertSame(1, $second->entriesScanned);
        self::assertSame(array_last($ids), $this->readMark($user));
    }

    public function testAMarkFarBehindStartsAtTheWindowFloorAndCatchesUpInOneBudgetedRun(): void
    {
        $user = $this->subscriber('laggard@example.com');
        $this->term($user, 'solar', 2.0);
        $this->entries(MarkGroupWalker::CHUNK + 1, '2026-11-01 08:00:00');
        $fresh = $this->entry('Solar panels');
        $clock = new TickingClock(new \DateTimeImmutable(self::NOW), 6);

        $report = $this->sweep($clock)->sweep(SweepBudgetModel::seconds(10));

        self::assertTrue($report->caughtUp);
        self::assertSame(1, $report->entriesScanned);
        self::assertSame([$fresh->requireId()], $this->readEntryIds($user));
        self::assertSame($fresh->requireId(), $this->readMark($user));
    }

    public function testAMarkBehindAWindowWithNothingSettledMovesToTheCeilingUnwalked(): void
    {
        $user = $this->subscriber('idle-laggard@example.com');
        $this->term($user, 'solar', 2.0);
        $old = $this->entries(2, '2026-11-01 08:00:00');

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(
            ['usersSwept' => 0, 'entriesScanned' => 0, 'matchesInserted' => 0, 'caughtUp' => true],
            $report->toLogContext(),
        );
        self::assertSame($old[1]->requireId(), $this->readMark($user));
    }

    public function testAFailingChunkStopsTheRunAndLeavesTheMark(): void
    {
        $user = $this->subscriber('failing@example.com');
        $this->term($user, 'solar', 2.0);
        $this->entry('Solar panels');
        $realWriter = self::getContainer()->get(ForYouStreamMembershipRepository::class);
        self::assertInstanceOf(ForYouStreamMembershipWriterInterface::class, $realWriter);
        $failingAfterInsert = new class ($realWriter) implements ForYouStreamMembershipWriterInterface {
            public function __construct(private readonly ForYouStreamMembershipWriterInterface $inner)
            {
            }

            public function insertMissing(array $matches, \DateTimeImmutable $matchedAt): int
            {
                $this->inner->insertMissing($matches, $matchedAt);

                throw new \RuntimeException('simulated failure after the insert');
            }
        };
        $logger = new RecordingLogger();

        $report = $this->sweep(writer: $failingAfterInsert, logger: $logger)->sweep(SweepBudgetModel::seconds(10));

        self::assertFalse($report->caughtUp);
        self::assertSame(0, $report->matchesInserted);
        self::assertSame(0, $report->entriesScanned);
        self::assertSame(0, $this->readMark($user));
        self::assertSame([], $this->readRows($user));
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        $logged = $logger->records[0]['context']['exception'] ?? null;
        self::assertInstanceOf(\RuntimeException::class, $logged);
        self::assertSame('simulated failure after the insert', $logged->getMessage());
    }

    public function testAnEntryAlreadyStoredIsNotInsertedTwice(): void
    {
        $user = $this->subscriber('twice@example.com');
        $this->term($user, 'solar', 2.0);
        $this->entry('Solar panels');
        $this->sweep()->sweep(SweepBudgetModel::seconds(10));
        $this->setMark($user, 0);

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertTrue($report->caughtUp);
        self::assertSame(1, $report->entriesScanned);
        self::assertSame(0, $report->matchesInserted);
        self::assertCount(1, $this->readRows($user));
    }

    public function testAnAccountWithoutAnythingBelowTheCeilingIsNotWalked(): void
    {
        $user = $this->subscriber('caught-up@example.com');
        $entry = $this->entry('Solar panels');
        $this->setMark($user, $entry->requireId());

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(10));

        self::assertSame(
            ['usersSwept' => 0, 'entriesScanned' => 0, 'matchesInserted' => 0, 'caughtUp' => true],
            $report->toLogContext(),
        );
    }

    private function sweep(
        ?ClockInterface $clock = null,
        ?ForYouStreamMembershipWriterInterface $writer = null,
        ?RecordingLogger $logger = null,
    ): ForYouStreamSweep {
        $container = self::getContainer();
        $clock ??= new MockClock(self::NOW);
        $entries = $container->get(EntryMembershipSweepRepository::class);
        $sets = $container->get(InterestTermSetRepository::class);
        $terms = $container->get(InterestTermRepository::class);
        $memberships = $writer ?? $container->get(ForYouStreamMembershipRepository::class);
        self::assertInstanceOf(EntryMembershipSweepRepository::class, $entries);
        self::assertInstanceOf(InterestTermSetRepository::class, $sets);
        self::assertInstanceOf(InterestTermRepository::class, $terms);
        self::assertInstanceOf(ForYouStreamMembershipWriterInterface::class, $memberships);

        return new ForYouStreamSweep(
            $sets,
            new ForYouStreamSourceRepository($this->entityManager),
            $terms,
            new SettledCeiling($entries, $clock),
            new MarkGroupWalker($entries, $this->entityManager, $clock),
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            new ForYouStreamChunkMatcher(
                new ForYouStreamSourceRepository($this->entityManager),
                new ForYouStreamScorer(),
            ),
            $memberships,
            new NaiveUtcClock($clock),
            $logger ?? new RecordingLogger(),
        );
    }

    private function account(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $user->approve(new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function subscriber(string $email, ?int $mark = 0): User
    {
        $user = $this->account($email);
        $this->entityManager->persist(new Subscription($user, $this->feed, new \DateTimeImmutable('2026-07-01')));
        $at = new \DateTimeImmutable('2026-11-01 00:00:00');
        $this->entityManager->persist(new InterestTermSet($user, $at, $at, $mark ?? 0));
        $this->entityManager->flush();

        return $user;
    }

    private function term(User $user, string $word, float $weight): void
    {
        $this->entityManager->persist(
            new InterestTerm($user, $this->stem($word), FeedLanguage::English, $weight),
        );
        $this->entityManager->flush();
    }

    private function stem(string $word): string
    {
        return StemmingWiring::stemmer()->stem($word, FeedLanguage::English);
    }

    private function entry(
        string $title,
        string $createdAt = self::SETTLED,
        string $effectiveAt = self::RECENT,
        ?FeedLanguage $language = FeedLanguage::English,
        ?Feed $feed = null,
    ): Entry {
        $entry = $this->unsavedEntry($title, $createdAt, $effectiveAt, $language, $feed);
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<Entry> */
    private function entries(int $count, string $effectiveAt = self::RECENT): array
    {
        $entries = [];
        for ($index = 0; $index < $count; $index++) {
            $entries[] = $this->unsavedEntry('Filler', self::SETTLED, $effectiveAt, FeedLanguage::English);
        }
        $this->entityManager->flush();

        return $entries;
    }

    private function unsavedEntry(
        string $title,
        string $createdAt,
        string $effectiveAt,
        ?FeedLanguage $language,
        ?Feed $feed = null,
    ): Entry {
        $entry = new Entry(
            $feed ?? $this->feed,
            'guid-' . $this->nextEntry++,
            null,
            $title,
            new \DateTimeImmutable($createdAt),
            new \DateTimeImmutable($effectiveAt),
        );
        $entry->recordText(new EntryText(null, null, $language));
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function setMark(User $user, int $mark): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE interest_term_set SET matched_up_to_entry_id = ? WHERE user_id = ?',
            [$mark, $user->requireId()],
        );
        $this->entityManager->clear();
    }

    private function readMark(User $user): int
    {
        $mark = $this->entityManager->getConnection()->fetchOne(
            'SELECT matched_up_to_entry_id FROM interest_term_set WHERE user_id = ?',
            [$user->requireId()],
        );
        self::assertIsNumeric($mark);

        return (int) $mark;
    }

    /** @return list<array{entryId: int, score: float, stems: string, matchedAt: string}> */
    private function readRows(User $user): array
    {
        /** @var list<array{entry_id: int|string, score: float|string, matched_stems: string, matched_at: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT entry_id, score, matched_stems, matched_at FROM for_you_stream_entry'
            . ' WHERE user_id = ? ORDER BY entry_id',
            [$user->requireId()],
        );

        return array_map(static fn (array $row): array => [
            'entryId' => (int) $row['entry_id'],
            'score' => (float) $row['score'],
            'stems' => $row['matched_stems'],
            'matchedAt' => $row['matched_at'],
        ], $rows);
    }

    /** @return list<int> */
    private function readEntryIds(User $user): array
    {
        return array_column($this->readRows($user), 'entryId');
    }
}
