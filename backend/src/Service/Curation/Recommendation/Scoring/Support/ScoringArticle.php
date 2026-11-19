<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Scoring\Support;

use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Foundation\Text\Support\ClippedText;

final class ScoringArticle
{
    private const int TITLE_CHARACTERS = 300;
    private const int FEED_NAME_CHARACTERS = 120;
    private const int DESCRIPTION_CHARACTERS = 600;
    private const int SHARED_ARTICLE_CHARACTERS = 300;

    /** @return array<string, string> */
    public static function of(ArticleLineModel $line): array
    {
        $sharedArticle = self::sharedArticle($line);
        $article = [
            'title' => self::title($line),
            'feedName' => self::feedName($line),
            'date' => $line->date,
        ];
        if (null !== $sharedArticle) {
            $article['sharedArticle'] = $sharedArticle;
        }

        return null === $line->description
            ? $article
            : $article + ['description' => self::description($line->description, $sharedArticle)];
    }

    public static function line(ArticleLineModel $line): string
    {
        $sharedArticle = self::sharedArticle($line);
        $heading = sprintf('%s — %s, %s.', self::title($line), self::feedName($line), $line->date);
        $withDescription = null === $line->description
            ? $heading
            : $heading . ' ' . self::description($line->description, $sharedArticle);

        return null === $sharedArticle ? $withDescription : $withDescription . ' Shares: ' . $sharedArticle;
    }

    private static function title(ArticleLineModel $line): string
    {
        return ClippedText::cutScrubbedText($line->title, self::TITLE_CHARACTERS);
    }

    private static function feedName(ArticleLineModel $line): string
    {
        return ClippedText::cutScrubbedText($line->feedName, self::FEED_NAME_CHARACTERS);
    }

    private static function sharedArticle(ArticleLineModel $line): ?string
    {
        return null === $line->sharedArticle
            ? null
            : ClippedText::cutScrubbedText($line->sharedArticle->summary(), self::SHARED_ARTICLE_CHARACTERS);
    }

    private static function description(string $description, ?string $sharedArticle): string
    {
        return ClippedText::cutScrubbedText(
            $description,
            self::DESCRIPTION_CHARACTERS - mb_strlen($sharedArticle ?? ''),
        );
    }

    private function __construct()
    {
    }
}
