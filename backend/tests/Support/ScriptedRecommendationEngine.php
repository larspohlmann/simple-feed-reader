<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\RecommendationEngineKind;
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
    ) {
    }

    /** @param list<list<int>> $batches */
    public static function packing(array $batches): self
    {
        return new self($batches, null);
    }

    public static function failingWith(\Throwable $advanceFailure): self
    {
        return new self([], $advanceFailure);
    }

    /** Registered under every kind, so a test does not depend on which kind kindFor() picks. */
    public function resolver(): RecommendationEngineResolver
    {
        $engines = [];
        foreach (RecommendationEngineKind::cases() as $kind) {
            $engines[$kind->value] = fn (): self => $this;
        }

        return new RecommendationEngineResolver(new ServiceLocator($engines));
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
}
