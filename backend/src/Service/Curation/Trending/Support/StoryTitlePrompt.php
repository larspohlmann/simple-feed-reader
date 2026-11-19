<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\StoryHeadlineModel;

final class StoryTitlePrompt
{
    private const string SYSTEM
        = "You write the headline of one news story from the headline and text of each article that reports it.\n"
        . 'Write one neutral, factual headline in %1$s, at most 90 characters, that describes the whole story rather'
        . " than one article.\n"
        . 'Name no outlet, publication or author. No clickbait, no quotation marks around it, no trailing'
        . " punctuation.\n"
        . "Then write a synopsis of the whole story in %1\$s from what the article texts report, not from the"
        . " headlines alone: two or three plain sentences, at most 350 characters, neutral and factual, naming no"
        . " outlet.\n"
        . 'Reply only with JSON: {"title": "...", "synopsis": "..."}';

    /**
     * @param list<StoryHeadlineModel> $headlines
     *
     * @return list<array{role: string, content: string}>
     */
    public static function messages(array $headlines, string $locale): array
    {
        return [
            ['role' => 'system', 'content' => \sprintf(
                self::SYSTEM,
                (FeedLanguage::fromTag($locale) ?? FeedLanguage::English)->englishName(),
            )],
            ['role' => 'user', 'content' => self::headlineBlocks($headlines)],
        ];
    }

    /** @param list<StoryHeadlineModel> $headlines */
    private static function headlineBlocks(array $headlines): string
    {
        $blocks = [];
        foreach ($headlines as $index => $headline) {
            $blocks[] = \sprintf("%d. %s\n%s", $index + 1, $headline->headline, $headline->snippet);
        }

        return implode("\n\n", $blocks);
    }

    private function __construct()
    {
    }
}
