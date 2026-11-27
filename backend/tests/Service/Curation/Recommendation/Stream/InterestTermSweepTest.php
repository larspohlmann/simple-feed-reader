<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTerm;
use App\Entity\InterestTermSet;
use App\Repository\ForYouStreamSourceRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\DueInterestTermFinder;
use App\Service\Curation\Recommendation\Stream\Factory\EntryStemmingFactory;
use App\Service\Curation\Recommendation\Stream\InterestTermRarity;
use App\Service\Curation\Recommendation\Stream\InterestTermRefresh;
use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StemmingWiring;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TickingClock;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class InterestTermSweepTest extends DbTestCase
{
    use RequiresServices;
    use SeedsProfiledAccounts;
    use SeedsUsers;

    private const string EDGE_NOW = '2026-11-27 10:00:00';

    private const string USABLE_REPLY = '{"terms":[{"language":"en","term":"solar","weight":8}]}';

    public function testOneFailingUserDoesNotStopTheOthers(): void
    {
        $this->profiledAccount('terms-sweep-a@example.test');
        $this->profiledAccount('terms-sweep-b@example.test');
        $this->chat()->queueFailure(new \RuntimeException('boom'));
        $this->chat()->queueContent(self::USABLE_REPLY);

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(InterestTermSweep::UNHURRIED_BUDGET_SECONDS));

        self::assertSame(
            ['due' => 2, 'stored' => 1, 'failed' => 0, 'raritiesRefreshed' => 1],
            $report->toLogContext(),
        );
        self::assertCount(1, $this->entityManager->getRepository(InterestTermSet::class)->findAll());
    }

    public function testNoUserStartsAfterTheDeadline(): void
    {
        $this->profiledAccount('terms-sweep-late@example.test');

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(0));

        self::assertSame(
            ['due' => 1, 'stored' => 0, 'failed' => 0, 'raritiesRefreshed' => 0],
            $report->toLogContext(),
        );
        self::assertSame([], $this->chat()->calls());
    }

    public function testEveryDueUserStartsBeforeTheDeadline(): void
    {
        $this->profiledAccount('terms-sweep-early@example.test');
        $this->chat()->queueContent(self::USABLE_REPLY);

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(3600));

        self::assertSame(1, $report->stored);
        $sets = self::getContainer()->get(InterestTermSetRepository::class);
        self::assertInstanceOf(InterestTermSetRepository::class, $sets);
        self::assertCount(1, $sets->findAll());
    }

    public function testAnAccountDueExactlyAtTheDeadlineIsNotStarted(): void
    {
        $this->profiledAccount('terms-sweep-edge@example.test');
        $this->chat()->queueContent(self::USABLE_REPLY);

        $atDeadline = $this->sweepAt(self::EDGE_NOW)->sweep(SweepBudgetModel::seconds(0));
        $beforeDeadline = $this->sweepAt(self::EDGE_NOW)->sweep(SweepBudgetModel::seconds(1));

        self::assertSame(0, $atDeadline->stored);
        self::assertSame(1, $beforeDeadline->stored);
    }

    public function testNoStaleSetStartsAtTheDeadline(): void
    {
        $first = $this->staleSet('terms-sweep-stale-first@example.test', '2026-11-26 07:00:00');
        $second = $this->staleSet('terms-sweep-stale-second@example.test', '2026-11-26 08:00:00');

        $atDeadline = $this->sweepAt(self::EDGE_NOW)->sweep(SweepBudgetModel::seconds(0));

        self::assertSame(0, $atDeadline->raritiesRefreshed);
        self::assertEquals(new \DateTimeImmutable('2026-11-26 07:00:00'), $first->getRaritiesRefreshedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-26 08:00:00'), $second->getRaritiesRefreshedAt());
    }

    public function testASecondStaleSetBeforeTheDeadlineIsRefreshedToo(): void
    {
        $this->staleSet('terms-sweep-stale-a@example.test', '2026-11-26 07:00:00');
        $this->staleSet('terms-sweep-stale-b@example.test', '2026-11-26 08:00:00');

        $report = $this->sweepAt(self::EDGE_NOW)->sweep(SweepBudgetModel::seconds(1));

        self::assertSame(2, $report->raritiesRefreshed);
    }

    public function testStaleSetsAreTakenOldestFirstWithNeverRefreshedAheadOfThem(): void
    {
        $recent = $this->staleSet('terms-sweep-order-recent@example.test', '2026-11-26 09:00:00');
        $older = $this->staleSet('terms-sweep-order-older@example.test', '2026-11-26 07:00:00');
        $never = $this->staleSet('terms-sweep-order-never@example.test', null);

        // Every reading moves the clock 6 s: the deadline, the cutoff and the window are read before the first
        // set's check (18 s), its stamp is the fourth reading (24 s), and the second set's check (30 s) is past 20 s.
        $this->sweepOnClock(new TickingClock(new \DateTimeImmutable(self::EDGE_NOW), 6))
            ->sweep(SweepBudgetModel::seconds(20));

        self::assertEquals(new \DateTimeImmutable('2026-11-27 10:00:24'), $never->getRaritiesRefreshedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-26 07:00:00'), $older->getRaritiesRefreshedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-26 09:00:00'), $recent->getRaritiesRefreshedAt());
    }

    public function testStaleRaritiesAreRefreshedOnceADay(): void
    {
        $set = new InterestTermSet(
            $this->user('terms-sweep-stale@example.test'),
            new \DateTimeImmutable('2026-10-03 06:00:00'),
            new \DateTimeImmutable('2026-10-03 06:00:00'),
            0,
        );
        $recent = new \DateTimeImmutable('-23 hours');
        $set->recordRaritiesRefreshed($recent);
        $this->entityManager->persist($set);
        $this->entityManager->flush();

        $untouched = $this->sweep()->sweep(SweepBudgetModel::seconds(InterestTermSweep::UNHURRIED_BUDGET_SECONDS));

        self::assertSame(0, $untouched->raritiesRefreshed);
        self::assertEquals($recent, $set->getRaritiesRefreshedAt());

        $set->recordRaritiesRefreshed(new \DateTimeImmutable('-25 hours'));
        $this->entityManager->flush();

        $refreshed = $this->sweep()->sweep(SweepBudgetModel::seconds(InterestTermSweep::UNHURRIED_BUDGET_SECONDS));

        self::assertSame(1, $refreshed->raritiesRefreshed);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $set->getRaritiesRefreshedAt());
    }

    public function testStoringTermsComputesTheirRaritiesAtOnce(): void
    {
        $user = $this->profiledAccount('terms-sweep-fresh@example.test');
        $this->chat()->queueContent(self::USABLE_REPLY);

        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(InterestTermSweep::UNHURRIED_BUDGET_SECONDS));

        self::assertSame(1, $report->stored);
        self::assertSame(1, $report->raritiesRefreshed);
        $set = $this->entityManager->getRepository(InterestTermSet::class)->find($user);
        self::assertInstanceOf(InterestTermSet::class, $set);
        self::assertNotNull($set->getRaritiesRefreshedAt());
        $terms = $this->entityManager->getRepository(InterestTerm::class)->findBy(['user' => $user]);
        self::assertNotSame([], $terms);
        foreach ($terms as $term) {
            self::assertEqualsWithDelta(log(502 / 1), $term->getRarity(), 1e-9);
        }
    }

    public function testReplacingStoredTermsLeavesOnlyTheNewOnesWithTheirRarities(): void
    {
        $user = $this->profiledAccount('terms-sweep-replaced@example.test');
        $this->chat()->queueContent(self::USABLE_REPLY);
        $this->sweep()->sweep(SweepBudgetModel::seconds(InterestTermSweep::UNHURRIED_BUDGET_SECONDS));
        $terms = $this->entityManager->getRepository(InterestTerm::class);
        $oldTerms = $terms->findBy(['user' => $user]);
        self::assertNotSame([], $oldTerms);

        $this->fixtures()->storeProfileGeneratedAt($user, 'Likes wind power.', '2026-10-04 06:00:00');
        $this->chat()->queueContent('{"terms":[{"language":"en","term":"wind","weight":8}]}');
        $report = $this->sweep()->sweep(SweepBudgetModel::seconds(InterestTermSweep::UNHURRIED_BUDGET_SECONDS));

        self::assertSame(1, $report->stored);
        foreach ($oldTerms as $oldTerm) {
            self::assertFalse($this->entityManager->contains($oldTerm));
        }
        $stems = [];
        foreach ($terms->findBy(['user' => $user]) as $term) {
            $stems[] = $term->getStem();
            self::assertEqualsWithDelta(log(502 / 1), $term->getRarity(), 1e-9);
        }
        self::assertEqualsCanonicalizing(['wind', 'wind'], array_map(
            static fn (string $stem): string => substr($stem, 0, 4),
            $stems,
        ));
    }

    private function staleSet(string $email, ?string $refreshedAt): InterestTermSet
    {
        $set = new InterestTermSet(
            $this->user($email),
            new \DateTimeImmutable('2026-10-03 06:00:00'),
            new \DateTimeImmutable('2026-10-03 06:00:00'),
            0,
        );
        if (null !== $refreshedAt) {
            $set->recordRaritiesRefreshed(new \DateTimeImmutable($refreshedAt));
        }
        $this->entityManager->persist($set);
        $this->entityManager->flush();

        return $set;
    }

    private function sweep(): InterestTermSweep
    {
        $sweep = self::getContainer()->get(InterestTermSweep::class);
        self::assertInstanceOf(InterestTermSweep::class, $sweep);

        return $sweep;
    }

    private function sweepAt(string $now): InterestTermSweep
    {
        return $this->sweepOnClock(new MockClock($now));
    }

    private function sweepOnClock(ClockInterface $wallClock): InterestTermSweep
    {
        $clock = new NaiveUtcClock($wallClock);

        return new InterestTermSweep(
            $this->requireFromContainer(DueInterestTermFinder::class),
            $this->requireFromContainer(InterestTermRefresh::class),
            $this->requireFromContainer(InterestTermSetRepository::class),
            new InterestTermRarity(
                new ForYouStreamSourceRepository($this->entityManager),
                $this->requireFromContainer(InterestTermRepository::class),
                new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
                $this->entityManager,
                $clock,
            ),
            $clock,
            new NullLogger(),
            $this->entityManager,
        );
    }

    private function chat(): StubChatClient
    {
        $chat = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $chat);

        return $chat;
    }
}
