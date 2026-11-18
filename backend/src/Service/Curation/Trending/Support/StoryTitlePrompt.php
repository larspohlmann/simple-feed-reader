<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\StoryHeadlineModel;

final class StoryTitlePrompt
{
    private const string SYSTEM
        = "You write the headline of one news story from the headlines and openings of the articles that report it.\n"
        . 'Write one neutral, factual headline in %s, at most 90 characters, that describes the whole story rather'
        . " than one article.\n"
        . 'Name no outlet, publication or author. No clickbait, no quotation marks around it, no trailing'
        . " punctuation.\n"
        . 'Reply only with JSON: {"title": "..."}';

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
