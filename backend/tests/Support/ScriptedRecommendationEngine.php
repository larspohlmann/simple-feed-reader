<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recommendation\Engine\Model\RecommendationEngineCapabilitiesModel;
use App\Service\Recommendation\Engine\Model\RecommendationEngineKind;
use App\Service\Recommendation\Engine\RecommendationEngine\RecommendationEngineInterface;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** An engine that is not the LLM: it packs and advances as scripted and records what it was asked. */
final class ScriptedRecommendationEngine implements RecommendationEngineInterface
{
    /** @var list<list<ArticleLineModel>> */
    public array $packedCandidates = [];

    /** @var list<TickContext> */
    public array $advancedTicks = [];

    /** @param list<list<int>> $batches */
    private function __construct(
        private readonly array $batches,
        private readonly ?\Throwable $advanceFailure,
        private readonly RecommendationEngineCapabilitiesModel $capabilities,
    ) {
    }

    /** @param list<list<int>> $batches */
    public static function packing(array $batches): self
    {
        return new self($batches, null, new RecommendationEngineCapabilitiesModel(false, []));
    }

    public static function failingWith(\Throwable $advanceFailure): self
    {
        return new self([], $advanceFailure, new RecommendationEngineCapabilitiesModel(false, []));
    }

    public static function reporting(RecommendationEngineCapabilitiesModel $capabilities): self
    {
        return new self([], null, $capabilities);
    }

    /** Registered under the only kind there is, which kindFor() gives every connection today. */
    public function resolver(): RecommendationEngineResolver
    {
        return new RecommendationEngineResolver(new ServiceLocator([
            RecommendationEngineKind::Llm->value => fn (): self => $this,
        ]));
    }

    public function packBatches(array $candidates, TickContext $tick): array
    {
        $this->packedCandidates[] = $candidates;

        return $this->batches;
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $this->advancedTicks[] = $tick;
        if (null !== $this->advanceFailure) {
            throw $this->advanceFailure;
        }

        return RecommendationRunReportModel::fromRun($tick->run);
    }

    public function capabilities(): RecommendationEngineCapabilitiesModel
    {
        return $this->capabilities;
    }
}
