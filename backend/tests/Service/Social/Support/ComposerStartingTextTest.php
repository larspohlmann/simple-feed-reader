<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\SharedLinkModel;
use App\Service\Social\Support\ComposerStartingText;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame($text, ComposerStartingText::sharing($network, new SharedLinkModel(self::URL, $title)));
    }
}
