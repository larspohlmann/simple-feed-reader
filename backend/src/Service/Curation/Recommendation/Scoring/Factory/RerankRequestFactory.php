<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\Support\FittingPrefix;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;
use App\Service\Recommendation\Support\TokenEstimate;

final readonly class RerankRequestFactory
{
    public function __construct(private RerankQueryFactory $queryFactory)
    {
    }

    public function create(ScoringRequestModel $request): RerankRequestModel
    {
        $documents = [];
        foreach ($request->articles as $article) {
            $documents[$article->entryId] = self::document($article, $request->budget->itemTokens());
        }

        return new RerankRequestModel(
            $request->model,
            $this->queryFactory->create($request->reader, $request->budget->readerTokens),
            $documents,
        );
    }

    private static function document(ArticleLineModel $article, int $tokenBudget): string
    {
        return FittingPrefix::of(
            ScoringArticle::line($article),
            static fn (string $prefix): bool => TokenEstimate::of($prefix) <= $tokenBudget,
        );
    }
}
