<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Publishing\Support;

use App\Service\Social\Publishing\Model\SharedLinkModel;
use App\Service\Social\Publishing\Support\ComposerStartingText;
use PHPUnit\Framework\TestCase;

final class ComposerStartingTextTest extends TestCase
{
    private const string URL = 'https://news.example/story';

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
        self::assertSame('Title', ComposerStartingText::sharing(new SharedLinkModel(self::URL, 'Title')));
    }

    public function testALinkPostWithoutATitleStartsEmpty(): void
    {
        self::assertSame('', ComposerStartingText::sharing(new SharedLinkModel(self::URL, '')));
    }

    public function testAQuotedLinkPostStartsWithTheQuoteInsteadOfTheTitle(): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', 'A line worth sharing');

        self::assertSame('“A line worth sharing”', ComposerStartingText::sharing($link));
    }

    public function testALinkPostStartsWithTheWholeQuoteHoweverLongItIs(): void
    {
        $quote = trim(str_repeat('word ', 100));

        $link = new SharedLinkModel(self::URL, 'A story', $quote);

        self::assertSame('“' . $quote . '”', ComposerStartingText::sharing($link));
    }

    public function testAQuoteOfAPostIsTheWholeQuoteInTypographicQuotes(): void
    {
        self::assertSame('“one two three four”', ComposerStartingText::quoting('one two three four'));
    }

    public function testNoQuoteStartsNothing(): void
    {
        self::assertSame('', ComposerStartingText::quoting(''));
    }
}
