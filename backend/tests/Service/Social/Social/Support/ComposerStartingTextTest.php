<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Social\PostWriter\BlueskyPostWriter;
use App\Service\Social\Support\ComposerStartingText;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{SocialNetwork, string, string}> */
    public static function linkPosts(): iterable
    {
        yield 'Bluesky: the title alone' => [SocialNetwork::Bluesky, 'A story', 'A story'];
        yield 'Bluesky: an untitled link starts empty' => [SocialNetwork::Bluesky, '', ''];
        yield 'Mastodon: the title above the link' => [SocialNetwork::Mastodon, 'A story', "A story\n\n" . self::URL];
        yield 'Mastodon: an untitled link alone' => [SocialNetwork::Mastodon, '', self::URL];
    }

    #[DataProvider('linkPosts')]
    public function testALinkPostStartsAsItsNetworkShowsALink(SocialNetwork $network, string $title, string $text): void
    {
        self::assertSame(
            $text,
            ComposerStartingText::sharing($network, new SharedLinkModel(self::URL, $title), self::MASTODON_DEFAULT),
        );
    }

    /** @return iterable<string, array{SocialNetwork, string}> */
    public static function quotedLinkPosts(): iterable
    {
        yield 'Bluesky: the quote alone, the link rides in the card' => [
            SocialNetwork::Bluesky,
            '“A line worth sharing”',
        ];
        yield 'Mastodon: the quote above the link' => [
            SocialNetwork::Mastodon,
            "“A line worth sharing”\n\n" . self::URL,
        ];
    }

    #[DataProvider('quotedLinkPosts')]
    public function testAQuotedLinkPostStartsWithTheQuoteInsteadOfTheTitle(SocialNetwork $network, string $text): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', 'A line worth sharing');

        self::assertSame($text, ComposerStartingText::sharing($network, $link, self::MASTODON_DEFAULT));
    }

    public function testALongQuoteIsCutAtAWordToFitBluesky(): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', str_repeat('word ', 100));

        $text = ComposerStartingText::sharing(SocialNetwork::Bluesky, $link, BlueskyPostWriter::MAX_GRAPHEMES);

        self::assertSame('“' . trim(str_repeat('word ', 59)) . '…”', $text);
        self::assertLessThanOrEqual(BlueskyPostWriter::MAX_GRAPHEMES, grapheme_strlen($text));
    }

    public function testALongQuoteLeavesRoomForTheLinkAsMastodonCountsIt(): void
    {
        $longUrl = 'https://news.example/' . str_repeat('path/', 40);
        $link = new SharedLinkModel($longUrl, 'A story', str_repeat('word ', 120));

        $text = ComposerStartingText::sharing(SocialNetwork::Mastodon, $link, self::MASTODON_DEFAULT);

        self::assertSame('“' . trim(str_repeat('word ', 94)) . "…”\n\n" . $longUrl, $text);
    }

    public function testAMastodonLimitTooSmallForTheLinkLeavesAnEllipsisForTheQuote(): void
    {
        $link = new SharedLinkModel(self::URL, 'A story', 'one two three four five six');

        $text = ComposerStartingText::sharing(SocialNetwork::Mastodon, $link, 20);

        self::assertSame("“…”\n\n" . self::URL, $text);
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
