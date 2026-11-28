<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Llm;

use App\Entity\User;
use App\Service\Curation\Recommendation\Llm\LlmRecommendationEngine;
use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\RecordsHistorySectionQueries;
use App\Tests\Support\SeedsUsers;

final class LlmRecommendationEngineTest extends DbTestCase
{
    use BuildsTickContexts;
    use RecordsHistorySectionQueries;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = RecommendationRunFixtures::fromContainer(self::getContainer());
        $this->owner = $this->user('llm-engine@example.test');
        $this->fixtures->seedReadyAiSettings($this->owner);
    }

    /** A ceiling of 7 splits 17 candidates 7/7/3; the 32k window is far from the budget limit. */
    public function testThePoolIsPackedUpToTheConnectionsBatchCeilingInPoolOrder(): void
    {
        $this->fixtures->capBatchesAt($this->owner, 7);
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();

        $batches = $this->engine()->packBatches(self::candidates(range(101, 117)), $this->tick($run));

        self::assertSame([range(101, 107), range(108, 114), range(115, 117)], $batches);
    }

    public function testPackingReadsFavoritesAloneFromTheHistory(): void
    {
        $run = $this->fixtures->createRun($this->owner);
        $this->entityManager->flush();
        $tick = $this->tick($run);

        $this->startRecordingQueries();
        $this->engine()->packBatches(self::candidates([101, 102]), $tick);

        $this->assertReadFavoritesAloneFromTheHistory();
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<ArticleLineModel>
     */
    private static function candidates(array $entryIds): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, 'Article ' . $entryId, 'Example', '2026-10-01', null),
            $entryIds,
        );
    }

    private function engine(): LlmRecommendationEngine
    {
        $engine = self::getContainer()->get(LlmRecommendationEngine::class);
        self::assertInstanceOf(LlmRecommendationEngine::class, $engine);

        return $engine;
    }
}
