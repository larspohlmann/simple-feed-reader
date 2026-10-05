<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Support\ClippedText;

final class ScoringArticle
{
    private const int TITLE_CHARACTERS = 300;
    private const int FEED_NAME_CHARACTERS = 120;
    private const int DESCRIPTION_CHARACTERS = 600;

    /** @return array<string, string> */
    public static function of(ArticleLineModel $line): array
    {
        $article = [
            'title' => ClippedText::ofScrubbed($line->title, self::TITLE_CHARACTERS),
            'feedName' => ClippedText::ofScrubbed($line->feedName, self::FEED_NAME_CHARACTERS),
            'date' => $line->date,
        ];

        return null === $line->description
            ? $article
            : $article + ['description' => ClippedText::ofScrubbed($line->description, self::DESCRIPTION_CHARACTERS)];
    }

    private function __construct()
    {
    }
}
