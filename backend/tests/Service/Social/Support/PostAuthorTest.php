<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Service\Social\Support\PostAuthor;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostAuthorTest extends TestCase
{
    #[DataProvider('posts')]
    public function testThePostIsMineWhenOneOfMyAccountsWroteIt(Entry $entry, ?string $expectedIdentity): void
    {
        $accounts = [SocialAccounts::bluesky(), SocialAccounts::mastodon()];

        self::assertSame($expectedIdentity, PostAuthor::accountAmong($entry, $accounts)?->getIdentity());
    }

    /** @return iterable<string, array{Entry, ?string}> */
    public static function posts(): iterable
    {
        $bluesky = self::feed('https://bsky.app/profile/did:plc:alice/rss', SocialNetwork::Bluesky);
        $mastodon = self::feed('https://mastodon.example/@alice.rss', SocialNetwork::Mastodon);
        $plain = self::feed('https://example.com/feed.xml', null);

        yield 'bluesky post by my DID' => [
            self::entry($bluesky, 'at://did:plc:alice/app.bsky.feed.post/3own'),
            'did:plc:alice',
        ];
        yield 'bluesky post by another DID' => [
            self::entry($bluesky, 'at://did:plc:bob/app.bsky.feed.post/3abc'),
            null,
        ];
        yield 'mastodon post by its author profile' => [
            self::entry($mastodon, 'https://elsewhere.example/@x/1', 'https://Mastodon.Example/@Alice'),
            'alice@mastodon.example',
        ];
        yield 'mastodon post without an author, by its status address' => [
            self::entry($mastodon, 'https://mastodon.example/users/alice/statuses/120'),
            'alice@mastodon.example',
        ];
        yield 'mastodon post by someone else' => [
            self::entry($mastodon, 'https://mastodon.example/@bob/121', 'https://mastodon.example/@bob'),
            null,
        ];
        yield 'an article is nobody\'s post' => [self::entry($plain, 'https://example.com/a'), null];
    }

    private static function feed(string $url, ?SocialNetwork $network): Feed
    {
        $feed = new Feed($url);
        $feed->classifyAs($network);

        return $feed;
    }

    private static function entry(Feed $feed, string $guid, ?string $authorUrl = null): Entry
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, $guid, $guid, 'Post', $now, $now);
        $entry->setAuthor(new EntryAuthor('Alice', $authorUrl));

        return $entry;
    }
}
