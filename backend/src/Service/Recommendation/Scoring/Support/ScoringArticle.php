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
            'title' => self::title($line),
            'feedName' => self::feedName($line),
            'date' => $line->date,
        ];

        return null === $line->description
            ? $article
            : $article + ['description' => self::description($line->description)];
    }

    public static function line(ArticleLineModel $line): string
    {
        $heading = sprintf('%s — %s, %s.', self::title($line), self::feedName($line), $line->date);

        return null === $line->description ? $heading : $heading . ' ' . self::description($line->description);
    }

    private static function title(ArticleLineModel $line): string
    {
        return ClippedText::ofScrubbed($line->title, self::TITLE_CHARACTERS);
    }

    private static function feedName(ArticleLineModel $line): string
    {
        return ClippedText::ofScrubbed($line->feedName, self::FEED_NAME_CHARACTERS);
    }

    private static function description(string $description): string
    {
        return ClippedText::ofScrubbed($description, self::DESCRIPTION_CHARACTERS);
    }

    private function __construct()
    {
    }
}
