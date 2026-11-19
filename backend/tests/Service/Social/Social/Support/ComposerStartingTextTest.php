<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Model\SharedLinkModel;
use App\Service\Social\Bluesky\BlueskyPostWriter;
use App\Service\Social\Social\Support\ComposerStartingText;
use PHPUnit\Framework\TestCase;

final class ComposerStartingTextTest extends TestCase
{
    private const string URL = 'https://news.example/story';
    private const int MASTODON_DEFAULT = 500;

    public function testAReplyStartsWithItsMentionsAndRoomToType(): void
    {
        self::assertSame('@ann@hachyderm.io @bob@mastodon.social ', ComposerStartingText::mentioning([
            '@ann@hachyderm.io',
            '@bob@mastodon.social',
        ]));
    }

    public function testAReplyThatMentionsNobodyStartsEmpty(): void
    {
        self::assertSame('', ComposerStartingText::mentioning([]));
    }

    public function testALinkPostStartsWithTheTitleAlone(): void
    {
        self::assertSame('Title', ComposerStartingText::sharing(new SharedLinkModel(self::URL, 'Title'), 475));
    }

    public function testALinkPostWithoutATitleStartsEmpty(): void
    {
        self::assertSame('', ComposerStartingText::sharing(new SharedLinkModel(self::URL, ''), 475));
    }

    public function testAQuotedLinkPostStartsWithTheQuoteInsteadOfTheTitle(): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', 'A line worth sharing');

        self::assertSame('“A line worth sharing”', ComposerStartingText::sharing($link, self::MASTODON_DEFAULT));
    }

    public function testALongQuoteIsCutAtAWordToFitBluesky(): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', str_repeat('word ', 100));

        $text = ComposerStartingText::sharing($link, BlueskyPostWriter::MAX_GRAPHEMES);

        self::assertSame('“' . trim(str_repeat('word ', 59)) . '…”', $text);
        self::assertLessThanOrEqual(BlueskyPostWriter::MAX_GRAPHEMES, grapheme_strlen($text));
    }

    public function testALongQuoteIsClippedByTheLimitItIsGiven(): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', str_repeat('word ', 200));

        $text = ComposerStartingText::sharing($link, self::MASTODON_DEFAULT - ComposerStartingText::LINK_ROOM);

        self::assertStringNotContainsString('https://', $text);
        self::assertLessThanOrEqual(475, mb_strlen($text));
    }

    public function testAQuoteOfAPostIsTheQuoteAloneCutToTheRoomGiven(): void
    {
        self::assertSame('“A line worth sharing”', ComposerStartingText::quoting('A line worth sharing', 500));
        self::assertSame('“one two…”', ComposerStartingText::quoting('one two three four', 11));
    }

    public function testAQuoteThatFillsTheRoomExactlyWithItsMarksIsKeptWhole(): void
    {
        self::assertSame('“one two”', ComposerStartingText::quoting('one two', 9));
    }

    public function testAQuoteOneCharacterOverTheRoomWithItsMarksIsCut(): void
    {
        self::assertSame('“one…”', ComposerStartingText::quoting('one two', 8));
    }

    public function testNoQuoteStartsNothing(): void
    {
        self::assertSame('', ComposerStartingText::quoting('', 500));
    }
}
