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
use App\Tests\DbTestCase;
use App\Tests\Support\RequiresServices;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StemmingWiring;
use App\Tests\Support\StubChatClient;
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

        $report = $this->sweep()->refreshDue();

        self::assertSame(
            ['due' => 2, 'stored' => 1, 'failed' => 0, 'raritiesRefreshed' => 0],
            $report->toLogContext(),
        );
        self::assertCount(1, $this->entityManager->getRepository(InterestTermSet::class)->findAll());
    }

    public function testNoUserStartsAfterTheDeadline(): void
    {
        $this->profiledAccount('terms-sweep-late@example.test');

        $report = $this->sweep()->refreshDueBefore(new \DateTimeImmutable('2000-01-01 00:00:00'));

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

        $report = $this->sweep()->refreshDueBefore(new \DateTimeImmutable('+1 hour'));

        self::assertSame(1, $report->stored);
        $sets = self::getContainer()->get(InterestTermSetRepository::class);
        self::assertInstanceOf(InterestTermSetRepository::class, $sets);
        self::assertCount(1, $sets->findAll());
    }

    public function testAnAccountDueExactlyAtTheDeadlineIsNotStarted(): void
    {
        $this->profiledAccount('terms-sweep-edge@example.test');
        $this->chat()->queueContent(self::USABLE_REPLY);

        $atDeadline = $this->sweepAt(self::EDGE_NOW)->refreshDueBefore(new \DateTimeImmutable(self::EDGE_NOW));
        $beforeDeadline = $this->sweepAt(self::EDGE_NOW)
            ->refreshDueBefore(new \DateTimeImmutable(self::EDGE_NOW . ' +1 second'));

        self::assertSame(0, $atDeadline->stored);
        self::assertSame(1, $beforeDeadline->stored);
    }

    public function testASetStaleExactlyAtTheDeadlineIsNotRefreshed(): void
    {
        $set = new InterestTermSet(
            $this->user('terms-sweep-stale-edge@example.test'),
            new \DateTimeImmutable('2026-10-03 06:00:00'),
            new \DateTimeImmutable('2026-10-03 06:00:00'),
            0,
        );
        $set->recordRaritiesRefreshed(new \DateTimeImmutable('2026-11-26 08:00:00'));
        $this->entityManager->persist($set);
        $this->entityManager->flush();

        $atDeadline = $this->sweepAt(self::EDGE_NOW)->refreshDueBefore(new \DateTimeImmutable(self::EDGE_NOW));
        $beforeDeadline = $this->sweepAt(self::EDGE_NOW)
            ->refreshDueBefore(new \DateTimeImmutable(self::EDGE_NOW . ' +1 second'));

        self::assertSame(0, $atDeadline->raritiesRefreshed);
        self::assertSame(1, $beforeDeadline->raritiesRefreshed);
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

        $untouched = $this->sweep()->refreshDue();

        self::assertSame(0, $untouched->raritiesRefreshed);
        self::assertEquals($recent, $set->getRaritiesRefreshedAt());

        $set->recordRaritiesRefreshed(new \DateTimeImmutable('-25 hours'));
        $this->entityManager->flush();

        $refreshed = $this->sweep()->refreshDue();

        self::assertSame(1, $refreshed->raritiesRefreshed);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $set->getRaritiesRefreshedAt());
    }

    public function testStoringTermsComputesTheirRaritiesAtOnce(): void
    {
        $user = $this->profiledAccount('terms-sweep-fresh@example.test');
        $this->chat()->queueContent(self::USABLE_REPLY);

        $report = $this->sweep()->refreshDue();

        self::assertSame(1, $report->stored);
        self::assertSame(0, $report->raritiesRefreshed);
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
        $this->sweep()->refreshDue();
        $terms = $this->entityManager->getRepository(InterestTerm::class);
        $oldTerms = $terms->findBy(['user' => $user]);
        self::assertNotSame([], $oldTerms);

        $this->fixtures()->storeProfileGeneratedAt($user, 'Likes wind power.', '2026-10-04 06:00:00');
        $this->chat()->queueContent('{"terms":[{"language":"en","term":"wind","weight":8}]}');
        $report = $this->sweep()->refreshDue();

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

    private function sweep(): InterestTermSweep
    {
        $sweep = self::getContainer()->get(InterestTermSweep::class);
        self::assertInstanceOf(InterestTermSweep::class, $sweep);

        return $sweep;
    }

    private function sweepAt(string $now): InterestTermSweep
    {
        $clock = new NaiveUtcClock(new MockClock($now));

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
        );
    }

    private function chat(): StubChatClient
    {
        $chat = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $chat);

        return $chat;
    }
}
