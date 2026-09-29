<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Model\ExtractionFailure;
use fivefilters\Readability\Article;

/**
 * Readability's content, when there is enough of it to show. Recovered media is itself evidence of an article,
 * so a thin text with media passes (#748).
 */
final class ArticleContentGate
{
    private const int MIN_TEXT_LENGTH = 200;

    public static function contentOf(Article $article, ArticleMediaModel $media): string
    {
        if ($article->content === null || ($media->isEmpty() && self::textLength($article) < self::MIN_TEXT_LENGTH)) {
            throw new ArticleNotExtractedException(ExtractionFailure::Empty);
        }

        return $article->content;
    }

    public static function textLength(Article $article): int
    {
        return mb_strlen(trim((string) $article->textContent));
    }

    private function __construct()
    {
    }
}
