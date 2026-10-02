<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;

/** An article as System One sees it, in the state's history and in a question alike: structured, every field capped. */
final class JevArticle
{
    private const int TITLE_CHARACTERS = 300;
    private const int FEED_NAME_CHARACTERS = 120;

    /** @return array<string, string> title, feedName, date and, when the entry has one, description */
    public static function of(ArticleLineModel $line, int $descriptionCharacters): array
    {
        $article = [
            'title' => ClippedText::of($line->title, self::TITLE_CHARACTERS),
            'feedName' => ClippedText::of($line->feedName, self::FEED_NAME_CHARACTERS),
            'date' => $line->date,
        ];

        return null === $line->description
            ? $article
            : $article + ['description' => ClippedText::of($line->description, $descriptionCharacters)];
    }

    private function __construct()
    {
    }
}
