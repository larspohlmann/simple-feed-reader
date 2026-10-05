<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Recommendation\Scoring\Support\QuestionId;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;

final readonly class SystemOneRequestFactory
{
    /** Points at the structured fields by backtick path, as TypeSafe asks; no feed text is ever part of it. */
    public const string QUESTION = 'Judging by the reader\'s profile, favorites and guidance in `state`, would this '
        . 'reader want to read `article`?';

    public function __construct(private ScoringStateFactory $stateFactory)
    {
    }

    public function create(ScoringRequestModel $request): SystemOneRequestModel
    {
        $questions = [];
        foreach ($request->articles as $article) {
            $questions[QuestionId::of($article->entryId)] = $this->question($article);
        }

        return new SystemOneRequestModel(
            $request->model,
            $this->stateFactory->create($request->reader, $request->budget->stateTokens),
            $questions,
        );
    }

    /** @return array{type: string, instructions: array{article: array<string, string>, question: string}} */
    public function question(ArticleLineModel $article): array
    {
        return [
            'type' => 'noul',
            'instructions' => [
                'article' => ScoringArticle::of($article),
                'question' => self::QUESTION,
            ],
        ];
    }
}
