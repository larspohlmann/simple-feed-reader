<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Factory;

use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;
use App\Service\Recommendation\Jev\Support\JevArticle;
use App\Service\Recommendation\Jev\Support\QuestionId;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;

/** One batch's System One request: the reader as `state`, a Noul question per article, articles only as data. */
final readonly class SystemOneRequestFactory
{
    /** Points at the structured fields by backtick path, as TypeSafe asks; no feed text is ever part of it. */
    public const string QUESTION = 'Judging by the reading history and guidance in `state`, would this reader want '
        . 'to read `article`?';

    private const int ARTICLE_DESCRIPTION_CHARACTERS = 600;

    /**
     * @param array<string, mixed>   $state
     * @param list<ArticleLineModel> $articles
     */
    public function create(string $model, array $state, array $articles): SystemOneRequestModel
    {
        $questions = [];
        foreach ($articles as $article) {
            $questions[QuestionId::of($article->entryId)] = $this->question($article);
        }

        return new SystemOneRequestModel($model, $state, $questions);
    }

    /** @return array{type: string, instructions: array{article: array<string, string>, question: string}} */
    public function question(ArticleLineModel $article): array
    {
        return [
            'type' => 'noul',
            'instructions' => [
                'article' => JevArticle::of($article, self::ARTICLE_DESCRIPTION_CHARACTERS),
                'question' => self::QUESTION,
            ],
        ];
    }
}
