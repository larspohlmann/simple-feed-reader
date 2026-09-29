<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\DueRecommendationRunFinder;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

final class DueRecommendationRunFinderTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    private function setCadence(User $user, int $hours): void
    {
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $writer->save($user, new RecommendationSettingsValues(
            guidancePrompt: null,
            historyCaps: RecommendationHistoryCaps::defaults(),
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: false,
            autoGenerateIntervalHours: $hours,
        ));
    }

    /** A terminal (non-active) run with a chosen start time, so the anchor is testable. fail() is reachable from PENDING. */
    private function pastFailedRun(User $user, string $ago): void
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable($ago));
        $run->fail('irrelevant', new \DateTimeImmutable($ago));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function dueEmails(): array
    {
        $finder = self::getContainer()->get(DueRecommendationRunFinder::class);
        self::assertInstanceOf(DueRecommendationRunFinder::class, $finder);

        return array_map(static fn (User $user): string => $user->getEmail(), $finder->due());
    }

    public function testDueWhenTheAnchorElapsed(): void
    {
        $user = $this->user('finder-due@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 3);
        $this->pastFailedRun($user, '-5 hours');
        $this->entityManager->clear();

        self::assertContains('finder-due@example.test', $this->dueEmails());
    }

    public function testNotDueInsideTheInterval(): void
    {
        $user = $this->user('finder-fresh@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 6);
        $this->pastFailedRun($user, '-1 hour');
        $this->entityManager->clear();

        self::assertNotContains('finder-fresh@example.test', $this->dueEmails());
    }

    public function testNotDueWhileARunIsActive(): void
    {
        $user = $this->user('finder-active@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 1);
        $starter = self::getContainer()->get(RecommendationRunStarter::class);
        self::assertInstanceOf(RecommendationRunStarter::class, $starter);
        $starter->start($user); // a PENDING (active) run
        $this->entityManager->clear();

        self::assertNotContains('finder-active@example.test', $this->dueEmails());
    }

    public function testSkippedWhenAiIsNotReady(): void
    {
        $user = $this->user('finder-no-ai@example.test');
        $this->setCadence($user, 1); // deliberately no seedReadyAiSettings
        $this->entityManager->clear();

        self::assertNotContains('finder-no-ai@example.test', $this->dueEmails());
    }

    public function testDueWhenNoPriorRunExists(): void
    {
        $user = $this->user('finder-never-ran@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 24);
        $this->entityManager->clear();

        self::assertContains('finder-never-ran@example.test', $this->dueEmails());
    }

    /**
     * The active-run guard is load-bearing on its own, not made redundant by
     * the anchor check below it: a PENDING run (active) with a stale creation
     * time would read as due by the anchor alone, so only the guard keeps it
     * out. Pins that the guard's early return actually fires.
     */
    public function testNotDueWhileAnOldActiveRunExists(): void
    {
        $user = $this->user('finder-old-active@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 1);

        // A PENDING run is active, and its creation time is well past one
        // interval, so the anchor alone would say "due".
        $run = new RecommendationRun($user, new \DateTimeImmutable('-10 hours'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertNotContains('finder-old-active@example.test', $this->dueEmails());
    }

    /**
     * Exactly one interval after the anchor is due: the comparison is `>=`,
     * not `>`. Uses a fixed clock so the boundary is exact rather than racing
     * the wall clock.
     */
    public function testDueExactlyAtTheIntervalBoundary(): void
    {
        $user = $this->user('finder-boundary@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 3);
        $this->pastFailedRun($user, '2026-08-09 09:00:00');
        $this->entityManager->clear();

        // "Now" is exactly three hours after the anchor: due under `>=`,
        // not-due under `>`.
        $settings = self::getContainer()->get(RecommendationSettingsRepository::class);
        self::assertInstanceOf(RecommendationSettingsRepository::class, $settings);
        $runs = self::getContainer()->get(RecommendationRunRepository::class);
        self::assertInstanceOf(RecommendationRunRepository::class, $runs);
        $configurator = self::getContainer()->get(AiProviderConfigurator::class);
        self::assertInstanceOf(AiProviderConfigurator::class, $configurator);

        $finder = new DueRecommendationRunFinder(
            $settings,
            $runs,
            $configurator,
            new MockClock(new \DateTimeImmutable('2026-08-09 12:00:00')),
        );

        $due = array_map(static fn (User $user): string => $user->getEmail(), $finder->due());

        self::assertContains('finder-boundary@example.test', $due);
    }
}
