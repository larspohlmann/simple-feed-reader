<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\SocialPostAddresses;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialRuleWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialPostAddressesTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string STATUS_URI = 'https://mastodon.social/users/Gargron/statuses/110';

    private SocialPostAddresses $postAddresses;

    protected function setUp(): void
    {
        $this->postAddresses = SocialRuleWiring::postAddresses();
    }

    #[DataProvider('posts')]
    public function testAnEntryIsAddressedAsItsNetworkKnowsIt(
        Entry $entry,
        ?SocialPostAddressModel $expected,
    ): void {
        self::assertEquals($expected, $this->postAddresses->findPost($entry));
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
            $this->postAddresses->requirePost(self::entry($mastodon, self::STATUS_URL, null)),
        );
    }

    public function testAnEntryThatIsNoPostIsRefused(): void
    {
        $this->expectExceptionObject(new NotASocialPostException('This entry is not a post this reader can act on.'));

        $this->postAddresses->requirePost(self::entry(new Feed('https://example.com/feed.xml'), self::POST_URI, null));
    }

    public function testALemmyPostIsRespondableButNoLikeablePost(): void
    {
        $entry = self::lemmyPost();

        self::assertNull($this->postAddresses->findPost($entry));
        self::assertEquals(
            new SocialPostAddressModel(SocialNetwork::Lemmy, 'https://lemmy.example/post/42'),
            $this->postAddresses->requireRespondablePost($entry),
        );
    }

    public function testAnEntryThatIsNoPostIsNotRespondable(): void
    {
        $entry = self::entry(new Feed('https://example.com/feed.xml'), self::POST_URI, null);

        $this->expectException(NotASocialPostException::class);

        $this->postAddresses->requireRespondablePost($entry);
    }

    public function testAReplyUnderALemmyPostIsAnHttpsAddress(): void
    {
        self::assertEquals(
            new SocialPostAddressModel(SocialNetwork::Lemmy, 'https://origin.example/comment/5'),
            $this->postAddresses->requireRespondableReply(self::lemmyPost(), 'https://origin.example/comment/5'),
        );
    }

    public function testAReplyUnderALemmyPostThatIsNoUrlIsRefused(): void
    {
        $this->expectException(NotASocialPostException::class);

        $this->postAddresses->requireRespondableReply(self::lemmyPost(), '77');
    }

    public function testAReplyUnderAMastodonPostIsAddressedAsBefore(): void
    {
        self::assertEquals(
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URI),
            $this->postAddresses->requireRespondableReply(self::postOn(SocialNetwork::Mastodon), self::STATUS_URI),
        );
    }

    #[DataProvider('replies')]
    public function testAReplyIsAddressedOnItsEntrysNetwork(SocialNetwork $network, string $address): void
    {
        self::assertEquals(
            new SocialPostAddressModel($network, $address),
            $this->postAddresses->requireReply(self::postOn($network), $address),
        );
    }

    /** @return iterable<string, array{SocialNetwork, string}> */
    public static function replies(): iterable
    {
        yield 'bluesky reply by its at-uri' => [SocialNetwork::Bluesky, 'at://did:plc:ann/app.bsky.feed.post/2'];
        yield 'mastodon reply by its status uri' => [SocialNetwork::Mastodon, self::STATUS_URI];
    }

    #[DataProvider('foreignReplies')]
    public function testAReplyThatIsNoPostOnItsEntrysNetworkIsRefused(SocialNetwork $network, string $address): void
    {
        $this->expectExceptionObject(new NotASocialPostException('The reply is no post on the entry\'s network.'));

        $this->postAddresses->requireReply(self::postOn($network), $address);
    }

    /** @return iterable<string, array{SocialNetwork, string}> */
    public static function foreignReplies(): iterable
    {
        yield 'a status url under a bluesky post' => [SocialNetwork::Bluesky, self::STATUS_URL];
        yield 'an at-uri under a mastodon post' => [SocialNetwork::Mastodon, self::POST_URI];
    }

    #[DataProvider('sharedUrls')]
    public function testASharedUrlNamesThePostItShows(string $url, ?SocialPostAddressModel $expected): void
    {
        self::assertEquals($expected, $this->postAddresses->parsePostUrl($url));
    }

    /** @return iterable<string, array{string, SocialPostAddressModel|null}> */
    public static function sharedUrls(): iterable
    {
        yield 'a bluesky web url' => [
            'https://bsky.app/profile/bob.test/post/3abc',
            new SocialPostAddressModel(SocialNetwork::Bluesky, 'at://bob.test/app.bsky.feed.post/3abc'),
        ];
        yield 'a bluesky at uri' => [
            self::POST_URI,
            new SocialPostAddressModel(SocialNetwork::Bluesky, self::POST_URI),
        ];
        yield 'a mastodon status url' => [
            self::STATUS_URL,
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'a mastodon status uri' => [
            self::STATUS_URI,
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URI),
        ];
        yield 'a bluesky web url pointing at a quote in the post' => [
            'https://bsky.app/profile/bob.test/post/3abc#:~:text=one%20two%20three',
            new SocialPostAddressModel(SocialNetwork::Bluesky, 'at://bob.test/app.bsky.feed.post/3abc'),
        ];
        yield 'a mastodon status url pointing at a quote in the status' => [
            self::STATUS_URL . '#:~:text=one%20two%20three',
            new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL),
        ];
        yield 'an article' => ['https://news.example/article?id=7', null];
        yield 'a mastodon profile' => ['https://mastodon.social/@Gargron', null];
    }

    public function testALemmyPostInAFeedWithoutNetworkIsRespondable(): void
    {
        $feed = new Feed('https://news.example/feed.xml');
        $entry = LemmyPostEntries::on($feed, 'https://lemmy.example/post/7');

        self::assertNull($feed->getNetwork());
        self::assertEquals(
            new SocialPostAddressModel(SocialNetwork::Lemmy, 'https://lemmy.example/post/7'),
            $this->postAddresses->requireRespondablePost($entry),
        );
    }

    #[DataProvider('postUrls')]
    public function testExactlyOneRuleClaimsAPostUrl(string $url, SocialNetwork $owner): void
    {
        foreach (SocialNetwork::cases() as $network) {
            $claim = $this->postAddresses->selectRule($network)->parsePostUrl($url);
            if ($network === $owner) {
                self::assertNotNull($claim, $network->value . ' claims its own post URL');
            } else {
                self::assertNull($claim, $network->value . ' leaves another network\'s post URL alone');
            }
        }
    }

    /** @return iterable<string, array{string, SocialNetwork}> */
    public static function postUrls(): iterable
    {
        yield 'a bluesky web url' => ['https://bsky.app/profile/bob.test/post/3abc', SocialNetwork::Bluesky];
        yield 'a bluesky at uri' => [self::POST_URI, SocialNetwork::Bluesky];
        yield 'a mastodon status url' => [self::STATUS_URL, SocialNetwork::Mastodon];
        yield 'a mastodon status uri' => [self::STATUS_URI, SocialNetwork::Mastodon];
    }

    #[DataProvider('authoredPosts')]
    public function testThePostIsMineWhenOneOfMyAccountsWroteIt(Entry $entry, ?string $expectedIdentity): void
    {
        $accounts = [SocialAccounts::bluesky(), SocialAccounts::mastodon()];

        self::assertSame($expectedIdentity, $this->postAddresses->findAuthorAmong($entry, $accounts)?->getIdentity());
    }

    /** @return iterable<string, array{Entry, ?string}> */
    public static function authoredPosts(): iterable
    {
        $bluesky = self::classifiedFeed('https://bsky.app/profile/did:plc:alice/rss', SocialNetwork::Bluesky);
        $mastodon = self::classifiedFeed('https://mastodon.example/@alice.rss', SocialNetwork::Mastodon);
        $plain = self::classifiedFeed('https://example.com/feed.xml', null);

        yield 'bluesky post by my DID' => [
            self::authoredEntry($bluesky, 'at://did:plc:alice/app.bsky.feed.post/3own'),
            'did:plc:alice',
        ];
        yield 'bluesky post by another DID' => [
            self::authoredEntry($bluesky, 'at://did:plc:bob/app.bsky.feed.post/3abc'),
            null,
        ];
        yield 'mastodon post by its author profile' => [
            self::authoredEntry($mastodon, 'https://elsewhere.example/@x/1', 'https://Mastodon.Example/@Alice'),
            'alice@mastodon.example',
        ];
        yield 'mastodon post without an author, by its status address' => [
            self::authoredEntry($mastodon, 'https://mastodon.example/users/alice/statuses/120'),
            'alice@mastodon.example',
        ];
        yield 'mastodon post by someone else' => [
            self::authoredEntry($mastodon, 'https://mastodon.example/@bob/121', 'https://mastodon.example/@bob'),
            null,
        ];
        yield 'an article is nobody\'s post' => [self::authoredEntry($plain, 'https://example.com/a'), null];
    }

    private static function classifiedFeed(string $url, ?SocialNetwork $network): Feed
    {
        $feed = new Feed($url);
        $feed->classifyAs($network);

        return $feed;
    }

    private static function authoredEntry(Feed $feed, string $guid, ?string $authorUrl = null): Entry
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, $guid, $guid, 'Post', $now, $now);
        $entry->setAuthor(new EntryAuthor('Alice', $authorUrl));

        return $entry;
    }

    private static function lemmyPost(): Entry
    {
        return LemmyPostEntries::on(new Feed('https://lemmy.example/feeds/a.xml'));
    }

    private static function postOn(SocialNetwork $network): Entry
    {
        $feed = new Feed('https://social.example/feed');
        $feed->classifyAs($network);

        return self::entry($feed, SocialNetwork::Bluesky === $network ? self::POST_URI : self::STATUS_URL, null);
    }

    private static function entry(Feed $feed, string $guid, ?string $url): Entry
    {
        $now = new \DateTimeImmutable('2026-10-13 10:00:00');

        return new Entry($feed, $guid, $url, 'Post', $now, $now);
    }
}
