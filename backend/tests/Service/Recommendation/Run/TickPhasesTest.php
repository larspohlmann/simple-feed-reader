<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\ProviderRateLimitedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Recommendation\Run\RecommendationRunDeferral;
use App\Service\Recommendation\Run\RecommendationRunFailure;
use App\Service\Recommendation\Run\RecommendationTickCheckpoint;
use App\Service\Recommendation\Run\RecommendationTransportFailureRecorder;
use App\Service\Recommendation\Run\SnapshotPhase;
use App\Service\Recommendation\Run\TickPhases;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\ScriptedRecommendationEngine;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

/** TickPhases knows no engine: whatever the resolver hands it advances a running run, inside the provider envelope. */
final class TickPhasesTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('tick-phases@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
        $this->clock = new MockClock('2026-08-08 10:05:00');
    }

    public function testARunningRunIsAdvancedByTheResolvedEngine(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $tick = $this->tick($this->runningRun());

        $report = $this->phases($engine)->advance($tick);

        self::assertSame([$tick], $engine->advancedTicks);
        self::assertSame('running', $report->status);
    }

    public function testAPendingRunIsSnapshottedNotAdvanced(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 3);
        $engine = ScriptedRecommendationEngine::packing([[1]]);
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();

        $this->phases($engine)->advance($this->tick($run));

        self::assertCount(1, $engine->packedCandidates);
        self::assertSame([], $engine->advancedTicks);
    }

    public function testARunWaitingOutARateLimitIsLeftAloneUntilTheWaitIsOver(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRun();
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-08 10:10:00'));
        $this->entityManager->flush();
        $phases = $this->phases($engine);

        $phases->advance($this->tick($run));
        self::assertSame([], $engine->advancedTicks);

        $this->clock->modify('2026-08-08 10:11:00');
        $phases->advance($this->tick($run));
        self::assertCount(1, $engine->advancedTicks);
    }

    public function testARateLimitedEngineDefersTheRunInsteadOfFailingIt(): void
    {
        $engine = ScriptedRecommendationEngine::failingWith(new ProviderRateLimitedException(90.0));
        $run = $this->runningRun();

        $report = $this->phases($engine)->advance($this->tick($run));

        self::assertEquals(new \DateTimeImmutable('2026-08-08 10:06:30'), $run->getRetryNotBefore());
        self::assertSame('running', $report->status);
        self::assertSame(0, $run->getTransportFailures());
    }

    public function testAnUnreachableProviderStrikesTheRunAndPropagates(): void
    {
        $engine = ScriptedRecommendationEngine::failingWith(
            new ProviderUnreachableException('The provider at api.example.test answered 502.'),
        );
        $run = $this->runningRun();

        try {
            $this->phases($engine)->advance($this->tick($run));
            self::fail('A transport failure must propagate.');
        } catch (ProviderUnreachableException $exception) {
            self::assertSame('The provider at api.example.test answered 502.', $exception->getMessage());
        }
        self::assertSame(1, $run->getTransportFailures());
    }

    public function testARunPackedForAnotherEngineFailsWithoutBeingAdvanced(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRun();

        $report = $this->phases($engine)->advance($this->tickOfKind($run, RecommendationEngineKind::Jev));

        self::assertSame('failed', $report->status);
        self::assertSame(TickPhases::ENGINE_SWITCH, $run->getError());
        self::assertSame([], $engine->advancedTicks);
    }

    /** A run deferred by the old engine's rate limit fails at once: it would wait for a provider it never calls again. */
    public function testTheSwitchIsCheckedBeforeARateLimitWait(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRun();
        $run->getRunningThrottle()->deferUntil(new \DateTimeImmutable('2026-08-08 10:10:00'));
        $this->entityManager->flush();

        $report = $this->phases($engine)->advance($this->tickOfKind($run, RecommendationEngineKind::Jev));

        self::assertSame('failed', $report->status);
    }

    /** Runs from before the column carry no kind and keep running on the LLM. */
    public function testARunWithoutARecordedKindKeepsRunningOnTheLlm(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRun();
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE recommendation_run SET engine_kind = NULL WHERE id = ?',
            [$run->requireId()],
        );
        $this->entityManager->refresh($run);

        $this->phases($engine)->advance($this->tickOfKind($run, RecommendationEngineKind::Llm));

        self::assertCount(1, $engine->advancedTicks);
    }

    /** Failed, not cancelled: back on the run's own engine it resumes and continues; on the other it fails again. */
    public function testAFailedSwitchResumesOnItsOwnEngineAndFailsAgainOnTheOther(): void
    {
        $engine = ScriptedRecommendationEngine::packing([]);
        $run = $this->runningRun();
        $phases = $this->phases($engine);
        $phases->advance($this->tickOfKind($run, RecommendationEngineKind::Jev));
        self::assertSame('failed', $run->getStatus()->value);

        $run->resume();
        $this->entityManager->flush();
        $report = $phases->advance($this->tickOfKind($run, RecommendationEngineKind::Llm));
        self::assertSame('running', $report->status);
        self::assertCount(1, $engine->advancedTicks);

        $report = $phases->advance($this->tickOfKind($run, RecommendationEngineKind::Jev));
        self::assertSame('failed', $report->status);
        self::assertSame(TickPhases::ENGINE_SWITCH, $run->getError());
        self::assertCount(1, $engine->advancedTicks);
    }

    private function runningRun(): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $run->snapshot(RecommendationEngineKind::Llm, [[101, 102]]);
        $this->entityManager->flush();

        return $run;
    }

    private function phases(ScriptedRecommendationEngine $engine): TickPhases
    {
        /** @var RecommendationCandidateLoader $candidates */
        $candidates = self::getContainer()->get(RecommendationCandidateLoader::class);
        /** @var RecommendationTickCheckpoint $checkpoint */
        $checkpoint = self::getContainer()->get(RecommendationTickCheckpoint::class);
        $resolver = $engine->resolver();

        return new TickPhases(
            new SnapshotPhase($candidates, $resolver, $this->entityManager, $this->clock),
            $resolver,
            new RecommendationRunDeferral($checkpoint, $this->entityManager, $this->clock),
            new RecommendationTransportFailureRecorder($checkpoint, $this->entityManager, $this->clock),
            new RecommendationRunFailure($checkpoint, $this->entityManager, $this->clock),
            $this->clock,
        );
    }
}
