<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\ProfileRun;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\RecommendationCandidateLoader;
use App\Service\Recommendation\Profile\ProfileForRun\ProfileForRunInterface;
use App\Service\Recommendation\Run\RecommendationRunFailure;
use App\Service\Recommendation\Run\SnapshotPhase;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\ScriptedRecommendationEngine;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\ClockInterface;

final class SnapshotPhaseTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('snapshot-phase@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
    }

    /** One entry alone, then the other two: a plan the LLM packer, which fills batches in pool order, never makes. */
    public function testTheResolvedEnginesBatchesBecomeTheRunsPlan(): void
    {
        $this->fixtures->storeProfile($this->owner, 'a stored profile');
        $ids = array_map(
            static fn (Entry $entry): int => $entry->requireId(),
            $this->fixtures->seedFeedWithEntries($this->owner, 3),
        );
        $plan = [[$ids[2]], [$ids[0], $ids[1]]];
        $engine = ScriptedRecommendationEngine::packing($plan);
        $run = $this->pendingRun();

        $this->snapshot($engine)->advance($this->tick($run));
        $this->entityManager->refresh($run);

        self::assertSame($plan, $run->getCandidateBatches());
        self::assertSame(RunStatus::Running, $run->getStatus());
        self::assertCount(1, $engine->packedCandidates);
        self::assertEqualsCanonicalizing(
            $ids,
            array_map(static fn (ArticleLineModel $line): int => $line->entryId, $engine->packedCandidates[0]),
        );
    }

    public function testAnEmptyPoolCompletesWithoutAskingTheEngine(): void
    {
        $engine = ScriptedRecommendationEngine::packing([[999]]);
        $run = $this->pendingRun();

        $this->snapshot($engine)->advance($this->tick($run));
        $this->entityManager->refresh($run);

        self::assertSame(RunStatus::Completed, $run->getStatus());
        self::assertSame([], $run->getCandidateBatches());
        self::assertSame([], $engine->packedCandidates);
        self::assertNull($this->profileRuns()->findLatestForUser($this->owner));
    }

    /** The raw column, not getEngineKind(): that reads a missing kind as the LLM too. */
    public function testThePlanRecordsTheKindItWasPackedFor(): void
    {
        $this->fixtures->storeProfile($this->owner, 'a stored profile');
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $run = $this->pendingRun();

        $this->snapshot(ScriptedRecommendationEngine::packing([[1]]))->advance($this->tick($run));

        self::assertSame('llm', $this->storedEngineKind($run));
    }

    public function testAnEmptyPoolRecordsTheKindToo(): void
    {
        $run = $this->pendingRun();

        $this->snapshot(ScriptedRecommendationEngine::packing([[999]]))->advance($this->tick($run));

        self::assertSame('llm', $this->storedEngineKind($run));
    }

    public function testAScoringTickRecordsTheScoringKindWithAPlan(): void
    {
        $this->fixtures->storeProfile($this->owner, 'a stored profile');
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $run = $this->pendingRun();
        $scoringTick = $this->tickOfKind($run, RecommendationEngineKind::Scoring);

        $this->snapshot(ScriptedRecommendationEngine::packing([[1]]))->advance($scoringTick);

        self::assertSame('jev', $this->storedEngineKind($run));
    }

    public function testAScoringTickRecordsTheScoringKindForAnEmptyPool(): void
    {
        $run = $this->pendingRun();
        $scoringTick = $this->tickOfKind($run, RecommendationEngineKind::Scoring);

        $this->snapshot(ScriptedRecommendationEngine::packing([[999]]))->advance($scoringTick);

        self::assertSame('jev', $this->storedEngineKind($run));
    }

    public function testTheStoredProfileIsFrozenIntoTheRun(): void
    {
        $this->fixtures->storeProfile($this->owner, 'Likes rail and maps.');
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $run = $this->pendingRun();

        $this->snapshot(ScriptedRecommendationEngine::packing([[1]]))->advance($this->tick($run));

        self::assertSame('Likes rail and maps.', $run->getProfileText());
    }

    public function testWithoutAStoredProfileTheRunStaysPendingWhileAProfileRunStarts(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $engine = ScriptedRecommendationEngine::packing([[1]]);
        $run = $this->pendingRun();

        $this->snapshot($engine)->advance($this->tick($run));

        self::assertSame(RunStatus::Pending, $run->getStatus());
        self::assertSame([], $engine->packedCandidates);
        self::assertNotNull($this->profileRuns()->findActiveForUser($this->owner));
    }

    public function testAFailedProfileRunFailsTheWaitingRunWithItsError(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 2);
        $run = $this->pendingRun();
        $phase = $this->snapshot(ScriptedRecommendationEngine::packing([[1]]));
        $phase->advance($this->tick($run));
        $this->profileRuns()->findActiveForUser($this->owner)
            ?->fail('No connection can build your profile.', new \DateTimeImmutable('2026-10-03 09:01:00'));
        $this->entityManager->flush();

        $phase->advance($this->tick($run));

        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame('Profile generation failed: No connection can build your profile.', $run->getError());
    }

    private function storedEngineKind(RecommendationRun $run): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT engine_kind FROM recommendation_run WHERE id = ?',
            [$run->requireId()],
        );
    }

    private function pendingRun(): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();

        return $run;
    }

    private function snapshot(ScriptedRecommendationEngine $engine): SnapshotPhase
    {
        /** @var RecommendationCandidateLoader $candidates */
        $candidates = self::getContainer()->get(RecommendationCandidateLoader::class);
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        /** @var ProfileForRunInterface $profiles */
        $profiles = self::getContainer()->get(ProfileForRunInterface::class);
        /** @var RecommendationRunFailure $runFailure */
        $runFailure = self::getContainer()->get(RecommendationRunFailure::class);

        return new SnapshotPhase(
            $candidates,
            $engine->resolver(),
            $this->entityManager,
            $clock,
            $profiles,
            $runFailure,
        );
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
}
