<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\Entry;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\RecommendationCandidateLoader;
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

        return new SnapshotPhase($candidates, $engine->resolver(), $this->entityManager, $clock);
    }
}
