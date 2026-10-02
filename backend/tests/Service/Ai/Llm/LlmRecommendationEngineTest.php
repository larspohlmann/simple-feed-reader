<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\Llm;

use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Llm\LlmRecommendationEngine;
use App\Service\Recommendation\Engine\Model\RecommendationTuningField;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class LlmRecommendationEngineTest extends DbTestCase
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
        $this->owner = $this->user('llm-engine@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
    }

    public function testTheLlmWritesReasonsAndReadsEveryTuningField(): void
    {
        $capabilities = $this->engine()->capabilities();

        self::assertTrue($capabilities->writesReasons);
        self::assertSame(
            [
                RecommendationTuningField::ContextWindow,
                RecommendationTuningField::BatchSize,
                RecommendationTuningField::SuppressReasoning,
                RecommendationTuningField::SlowModel,
                RecommendationTuningField::MaxBatchSize,
                RecommendationTuningField::BatchConcurrency,
            ],
            $capabilities->tuningFields,
        );
    }

    /** A ceiling of 7 splits 17 candidates 7/7/3; the 32k window is far from the budget limit. */
    public function testThePoolIsPackedUpToTheConnectionsBatchCeilingInPoolOrder(): void
    {
        $this->fixtures->capBatchesAt($this->owner, 7);
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();
        $candidates = array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, 'Article ' . $entryId, 'Example', '2026-10-01', null),
            range(101, 117),
        );

        $batches = $this->engine()->packBatches($candidates, $this->tick($run));

        self::assertSame([range(101, 107), range(108, 114), range(115, 117)], $batches);
    }

    private function engine(): LlmRecommendationEngine
    {
        $engine = self::getContainer()->get(LlmRecommendationEngine::class);
        self::assertInstanceOf(LlmRecommendationEngine::class, $engine);

        return $engine;
    }
}
