<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CallPhase;
use App\Enum\RecommendationEngineKind;
use App\Enum\RecommendationProfileSource;
use App\Enum\ScoringProtocol;
use PHPUnit\Framework\TestCase;

final class RecommendationEngineKindTest extends TestCase
{
    public function testTheLlmScoresInBatchesThenConsolidates(): void
    {
        self::assertSame([CallPhase::Batch, CallPhase::Consolidate], RecommendationEngineKind::Llm->phases());
        self::assertSame(1, RecommendationEngineKind::Llm->singleCallPhaseCount());
    }

    public function testRunsAsksThePhasePlan(): void
    {
        self::assertTrue(RecommendationEngineKind::Llm->runs(CallPhase::Consolidate));
        self::assertFalse(RecommendationEngineKind::Llm->runs(CallPhase::Distill));
    }

    public function testScoringOnlyAsksInBatches(): void
    {
        self::assertSame([CallPhase::Batch], RecommendationEngineKind::Scoring->phases());
        self::assertSame(0, RecommendationEngineKind::Scoring->singleCallPhaseCount());
        self::assertFalse(RecommendationEngineKind::Scoring->runs(CallPhase::Consolidate));
    }

    public function testAScoringModelBorrowsItsProfileAndAnLlmBuildsItsOwn(): void
    {
        self::assertSame(RecommendationProfileSource::Borrowed, RecommendationEngineKind::Scoring->profileSource());
        self::assertSame(RecommendationProfileSource::Own, RecommendationEngineKind::Llm->profileSource());
    }

    /** What a connection and a run store, and what Version20261005140100 writes. */
    public function testTheStoredValuesAreTheOnesTheBackfillWrites(): void
    {
        self::assertSame('scoring', RecommendationEngineKind::Scoring->value);
        self::assertSame('system_one', ScoringProtocol::SystemOne->value);
    }
}
