<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Support\SocialPostAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialPostAddressTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string STATUS_URI = 'https://mastodon.social/users/Gargron/statuses/110';

    #[DataProvider('posts')]
    public function testAnEntryIsAddressedAsItsNetworkKnowsIt(
        Entry $entry,
        ?SocialPostAddressModel $expected,
    ): void {
        self::assertEquals($expected, SocialPostAddress::of($entry));
    }

    /** @return iterable<string, array{Entry, SocialPostAddressModel|null}> */
    public static function posts(): iterable
    {
        $profile = new Feed('https://bsky.app/profile/did:plc:bob/rss');
        $profile->classifyAs(SocialNetwork::Bluesky);
        $home = new Feed('social:bluesky:3:home');
        $home->setSourceFormat(SourceFormat::BLUESKY_API);
        $home->classifyAs(SocialNetwork::Bluesky);
        $mastodon = new Feed('https://mastodon.social/@Gargron.rss');
        $mastodon->classifyAs(SocialNetwork::Mastodon);
        $plain = new Feed('https://example.com/feed.xml');
        $unclassified = new Feed('https://bsky.app/profile/did:plc:carol/rss');

        yield 'bluesky profile post by its at-uri' => [
            self::entry($profile, self::POST_URI, 'https://bsky.app/profile/did:plc:bob/post/3abc'),
            new SocialPostAddressModel(SocialNetwork::Bluesky, self::POST_URI),
        ];
        yield 'bluesky private feed post' => [
            self::entry($home, self::POST_URI, null),
            new SocialPostAddressModel(SocialNetwork::Bluesky, self::POST_URI),
        ];
        yield 'bluesky item without an at-uri guid' => [
            self::entry($profile, 'https://bsky.app/profile/did:plc:bob/post/3abc', null),
            null,
        ];
        yield 'bluesky item with a list at-uri' => [
            self::entry($profile, 'at://did:plc:bob/app.bsky.graph.list/3l1', null),
            null,
        ];
        yield 'bluesky item with a repost at-uri' => [
            self::entry($profile, 'at://did:plc:bob/app.bsky.feed.repost/3r1', null),
            null,
        ];
        yield 'mastodon post by its url when its guid is opaque' => [
            self::entry($mastodon, 'tag:mastodon.social,2026:110', self::STATUS_URL),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'mastodon private feed post by its activitypub uri, not its differing web page' => [
            self::entry($mastodon, self::STATUS_URI, self::STATUS_URL),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URI),
        ];
        yield 'mastodon private feed post from another server software by its activitypub uri' => [
            self::entry($mastodon, 'https://misskey.io/notes/9abc', 'https://misskey.io/@alice/9abc'),
            new SocialPostAddressModel(SocialNetwork::Mastodon, 'https://misskey.io/notes/9abc'),
        ];
        yield 'mastodon profile feed post whose guid is its status url' => [
            self::entry($mastodon, self::STATUS_URL, self::STATUS_URL),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'mastodon post by its guid when it has no url' => [
            self::entry($mastodon, self::STATUS_URL, null),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'mastodon post by its guid when the url is relative' => [
            self::entry($mastodon, self::STATUS_URL, '/@Gargron/110'),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'mastodon post by its guid when the url is empty' => [
            self::entry($mastodon, self::STATUS_URL, ''),
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'mastodon item with neither an http url nor an http guid' => [
            self::entry($mastodon, 'tag:mastodon.social,2026:110', 'mailto:gargron@example.test'),
            null,
        ];
        yield 'mastodon item with a javascript url' => [
            self::entry($mastodon, 'tag:mastodon.social,2026:110', 'javascript:alert(1)'),
            null,
        ];
        yield 'a profile url stored without a network' => [self::entry($unclassified, self::POST_URI, null), null];
        yield 'plain feed' => [self::entry($plain, self::POST_URI, self::STATUS_URL), null];
    }

    public function testAPostIsAddressedWithoutRefusal(): void
    {
        $mastodon = new Feed('https://mastodon.social/@Gargron.rss');
        $mastodon->classifyAs(SocialNetwork::Mastodon);

        self::assertEquals(
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
            SocialPostAddress::ofOrRefuse(self::entry($mastodon, self::STATUS_URL, null)),
        );
    }

    public function testAnEntryThatIsNoPostIsRefused(): void
    {
        $this->expectExceptionObject(new NotASocialPostException('This entry is not a Bluesky or Mastodon post.'));

        SocialPostAddress::ofOrRefuse(self::entry(new Feed('https://example.com/feed.xml'), self::POST_URI, null));
    }

    private static function entry(Feed $feed, string $guid, ?string $url): Entry
    {
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');

        return new Entry($feed, $guid, $url, 'Post', $now, $now);
    }
}
