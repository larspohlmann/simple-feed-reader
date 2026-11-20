<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Enum\SocialNetwork;
use App\Service\Foundation\CanonicalItem\CanonicalKeys;
use DoctrineMigrations\Version20261120120000;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The migration keys stored entries with its own copy of the rules; this holds the copy to the rules ingestion runs. */
final class Version20261120120000KeysTest extends KernelTestCase
{
    /** @return iterable<string, array{0: ?SocialNetwork, 1: string, 2: ?string, 3?: Discussion}> */
    public static function provideEntries(): iterable
    {
        $thread = Discussion::platformThread('https://lemmy.world/post/7');
        yield 'Bluesky post' => [SocialNetwork::Bluesky, 'at://did:plc:abc/app.bsky.feed.post/3kq', 'https://bsky.app/profile/a/post/3kq'];
        yield 'Bluesky saved copy' => [SocialNetwork::Bluesky, 'at://did:plc:abc/app.bsky.feed.post/3kq#42', null];
        yield 'Bluesky web guid' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/a/post/3kq', null];
        yield 'Bluesky repost guid' => [SocialNetwork::Bluesky, 'at://did:plc:abc/app.bsky.feed.repost/3kq', null];
        yield 'Mastodon uri' => [SocialNetwork::Mastodon, 'https://mastodon.social/users/lars/statuses/113', null];
        yield 'Mastodon page' => [SocialNetwork::Mastodon, 'https://mastodon.social/@lars/113', 'https://mastodon.social/@lars/113'];
        yield 'Mastodon remote page' => [SocialNetwork::Mastodon, 'https://mastodon.social/@lars@hachyderm.io/113', null];
        yield 'Mastodon saved copy' => [SocialNetwork::Mastodon, 'https://mastodon.social/@lars/113#9', null];
        yield 'Mastodon guid not a status, url one' => [SocialNetwork::Mastodon, 'tag:mastodon.social,2026:113', 'https://Mastodon.social/@lars/113'];
        yield 'Mastodon other fediverse status' => [SocialNetwork::Mastodon, 'https://pixelfed.social/p/lars/7', null];
        yield 'Mastodon http status' => [SocialNetwork::Mastodon, 'http://mastodon.social/@lars/113', null];
        yield 'Mastodon relative guid, absolute url' => [SocialNetwork::Mastodon, 'status-113', 'https://example.social/notes/113'];
        yield 'Mastodon nothing absolute' => [SocialNetwork::Mastodon, 'status-113', null];
        yield 'Mastodon over-long id' => [SocialNetwork::Mastodon, 'https://m.social/@a/' . str_repeat('1', 33), null];
        yield 'Mastodon discussion thread' => [SocialNetwork::Mastodon, 'https://lemmy.world/post/7', null, $thread];
        yield 'Lemmy post page' => [SocialNetwork::Lemmy, 'https://lemmy.world/post/54626839', 'https://example.com/story'];
        yield 'Lemmy trailing slash, upper host' => [SocialNetwork::Lemmy, 'https://Lemmy.World/post/5/', null];
        yield 'Lemmy saved copy' => [SocialNetwork::Lemmy, 'https://lemmy.world/post/5#42', null];
        yield 'Lemmy http' => [SocialNetwork::Lemmy, 'http://lemmy.world/post/5', null];
        yield 'Lemmy comment guid, post discussion' => [SocialNetwork::Lemmy, 'https://lemmy.world/comment/9', 'https://example.com/a',
            Discussion::fromPageAndFeed('https://lemmy.world/post/5', null, CommentsLoad::Manual)];
        yield 'Lemmy nothing' => [SocialNetwork::Lemmy, 'https://lemmy.world/comment/9', null];
        yield 'network-less thread' => [null, 'https://lemmy.world/post/7', null, $thread];
        yield 'network-less thread with a url' => [null, 'https://lemmy.world/post/7', 'https://example.com/a', $thread];
        yield 'network-less thread with a comments feed' => [null, 'x', null,
            Discussion::withCommentsFeed('https://lemmy.world/post/7', 'https://lemmy.world/feeds/c.xml', CommentsLoad::Auto)];
        yield 'network-less manual thread' => [null, 'x', null,
            Discussion::fromPageAndFeed('https://lemmy.world/post/7', null, CommentsLoad::Manual)];
        yield 'network-less non-Lemmy thread' => [null, 'x', null, Discussion::platformThread('https://news.ycombinator.com/item?id=1')];
        yield 'article' => [null, 'https://blog.example.com/a', 'https://blog.example.com/a'];
    }

    #[DataProvider('provideEntries')]
    public function testTheMigrationKeysAnEntryAsIngestionDoes(
        ?SocialNetwork $network,
        string $guid,
        ?string $url,
        ?Discussion $discussion = null,
    ): void {
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261120120000.php';
        $entry = self::buildEntry($network, $guid, $url, $discussion ?? Discussion::none());
        $expected = self::getContainer()->get(CanonicalKeys::class)->findKey($entry);

        self::assertSame(
            null === $expected ? null : [$expected->kind->value, $expected->key],
            Version20261120120000::parseCanonicalKey(self::buildRow($entry)),
        );
    }

    private static function buildEntry(?SocialNetwork $network, string $guid, ?string $url, Discussion $discussion): Entry
    {
        $feed = new Feed('https://example.com/feed');
        $feed->classifyAs($network);
        $now = new \DateTimeImmutable('2026-01-01');
        $entry = new Entry($feed, $guid, $url, 'Title', $now, $now);
        $entry->setDiscussion($discussion);

        return $entry;
    }

    /** @return array{network: ?string, guid: string, url: ?string, discussion_url: ?string, comments_feed_url: ?string, comments_load: ?string} */
    private static function buildRow(Entry $entry): array
    {
        $discussion = $entry->getDiscussion();

        return [
            'network' => $entry->getFeed()->getNetwork()?->value,
            'guid' => $entry->getGuid(),
            'url' => $entry->getUrl(),
            'discussion_url' => $discussion->url,
            'comments_feed_url' => $discussion->commentsFeedUrl,
            'comments_load' => $discussion->commentsLoad?->value,
        ];
    }
}
