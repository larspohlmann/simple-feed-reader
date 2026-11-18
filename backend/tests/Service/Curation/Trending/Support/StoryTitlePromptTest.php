<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Model\StoryHeadlineModel;
use App\Service\Curation\Trending\Support\StoryTitlePrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoryTitlePromptTest extends TestCase
{
    public function testTheSystemMessageNamesTheUiLanguage(): void
    {
        $headlines = [new StoryHeadlineModel('Hafenstreik', 'Der Hafen steht still.')];

        self::assertStringContainsString(
            'in German, at most 90 characters',
            StoryTitlePrompt::messages($headlines, 'de')[0]['content'],
        );
        self::assertStringContainsString('in French', StoryTitlePrompt::messages($headlines, 'fr')[0]['content']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function locales(): iterable
    {
        yield 'English' => ['en', 'English'];
        yield 'Spanish' => ['es', 'Spanish'];
        yield 'an unknown locale reads as English' => ['it', 'English'];
    }

    #[DataProvider('locales')]
    public function testEachLocaleNamesItsLanguage(string $locale, string $language): void
    {
        $messages = StoryTitlePrompt::messages([new StoryHeadlineModel('Hafenstreik', '')], $locale);

        self::assertSame('system', $messages[0]['role']);
        self::assertSame(
            "You write the headline of one news story from the headlines and openings of the articles that report it.\n"
            . "Write one neutral, factual headline in $language, at most 90 characters, that describes the whole story"
            . " rather than one article.\n"
            . "Name no outlet, publication or author. No clickbait, no quotation marks around it, no trailing"
            . " punctuation.\n"
            . 'Reply only with JSON: {"title": "..."}',
            $messages[0]['content'],
        );
    }

    public function testEachHeadlineIsNumberedWithItsSnippet(): void
    {
        $messages = StoryTitlePrompt::messages([new StoryHeadlineModel('Hafenstreik', 'Der Hafen steht still.')], 'en');

        self::assertSame('user', $messages[1]['role']);
        self::assertSame("1. Hafenstreik\nDer Hafen steht still.", $messages[1]['content']);
    }

    public function testHeadlineBlocksAreSeparatedByABlankLine(): void
    {
        $messages = StoryTitlePrompt::messages([
            new StoryHeadlineModel('Hafenstreik', 'Der Hafen steht still.'),
            new StoryHeadlineModel('Port strike', 'The port is idle.'),
        ], 'en');

        self::assertCount(2, $messages);
        self::assertSame(
            "1. Hafenstreik\nDer Hafen steht still.\n\n2. Port strike\nThe port is idle.",
            $messages[1]['content'],
        );
    }
}
