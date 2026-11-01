<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Comments\CommentsCache;
use App\Service\Comments\CommentsLoader;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\PostReposting;
use App\Service\Social\SocialCountsCache;
use App\Service\Social\SocialPostFinder;
use App\Service\Social\SocialReposts;
use App\Tests\DbTestCase;
use App\Tests\Support\AssertsRefusal;
use App\Tests\Support\FakeCommentsSource;
use App\Tests\Support\FakeMastodonNetwork;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SocialRepostsTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string REPLY_URI = 'https://hachyderm.io/users/ann/statuses/200';

    private FakeMastodonNetwork $mastodon;
    private FakeCommentsSource $thread;
    private CommentsLoader $comments;
    private SocialCountsCache $countsCache;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->thread = new FakeCommentsSource('thread');
        $clock = new MockClock('2026-11-01 12:00:00');
        $cache = new CommentsCache(new ArrayAdapter(clock: $clock), 7200, 60);
        $this->comments = new CommentsLoader([$this->thread], $cache, $clock);
        $this->countsCache = new SocialCountsCache(new ArrayAdapter());
        $this->owner = $this->user('social-reposts@example.test');
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    public function testARepostResolvesThePostAndRepostsIt(): void
    {
        $this->reposts()->repost($this->owner, $this->post());

        self::assertSame(['resolve ' . self::STATUS_URL, 'repost 110'], $this->mastodon->calls);
    }

    public function testRepostingARepostedPostAgainOnlyLooksItUp(): void
    {
        $this->mastodon->knowsTheRepost();

        $this->reposts()->repost($this->owner, $this->post());

        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testAnUnrepostUndoesTheRepostTheNetworkReports(): void
    {
        $this->mastodon->knowsTheRepost();

        $this->reposts()->unrepost($this->owner, $this->post());

        self::assertSame(['resolve ' . self::STATUS_URL, 'unrepost 110'], $this->mastodon->calls);
    }

    public function testUnrepostingAPostNeverRepostedOnlyLooksItUp(): void
    {
        $this->reposts()->unrepost($this->owner, $this->post());

        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testAPostTheAuthorKeepsFromBoostsIsRefusedBeforeTheRepost(): void
    {
        $this->mastodon->forbidsReposting();

        $this->assertRefused(
            fn () => $this->reposts()->repost($this->owner, $this->post()),
            SocialPostRefusedException::class,
            'A post closed to boosts must not be reposted.',
        );
        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
    }

    public function testARepostDropsThePostsCachedCounts(): void
    {
        $entry = $this->post();
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 1, 0, null));

        $this->reposts()->repost($this->owner, $entry);

        self::assertNull($this->countsCache->find(self::address()));
    }

    public function testAnUnrepostDropsThePostsCachedCounts(): void
    {
        $entry = $this->post();
        $this->mastodon->knowsTheRepost();
        $this->countsCache->remember(self::address(), new SocialCountsModel(4, 1, 0, null));

        $this->reposts()->unrepost($this->owner, $entry);

        self::assertNull($this->countsCache->find(self::address()));
    }

    public function testAFailedRepostKeepsTheCachedCounts(): void
    {
        $entry = $this->post();
        $counts = new SocialCountsModel(4, 1, 0, null);
        $this->countsCache->remember(self::address(), $counts);
        $this->mastodon->failWith(new SocialNetworkUnreachableException('down'));

        $this->assertRefused(
            fn () => $this->reposts()->repost($this->owner, $entry),
            SocialNetworkUnreachableException::class,
            'An unreachable network must reach the caller.',
        );

        self::assertEquals($counts, $this->countsCache->find(self::address()));
    }

    public function testAReplyRepostResolvesTheReplyAndRepostsIt(): void
    {
        $this->reposts()->repostReply($this->owner, $this->post(), self::REPLY_URI);

        self::assertSame(['resolve ' . self::REPLY_URI, 'repost 110'], $this->mastodon->calls);
    }

    public function testAReplyRepostMakesTheNextLoadAskForTheThreadAgain(): void
    {
        $entry = $this->post();
        $this->comments->load($this->owner, $entry);

        $this->reposts()->repostReply($this->owner, $entry, self::REPLY_URI);
        $this->comments->load($this->owner, $entry);

        self::assertCount(2, $this->thread->fetches);
    }

    public function testAReplyUnrepostMakesTheNextLoadAskForTheThreadAgain(): void
    {
        $entry = $this->post();
        $this->mastodon->knowsTheRepost();
        $this->comments->load($this->owner, $entry);

        $this->reposts()->unrepostReply($this->owner, $entry, self::REPLY_URI);
        $this->comments->load($this->owner, $entry);

        self::assertSame(['resolve ' . self::REPLY_URI, 'unrepost 110'], $this->mastodon->calls);
        self::assertCount(2, $this->thread->fetches);
    }

    public function testAnUnrepostOfAReplyNeverRepostedOnlyLooksItUp(): void
    {
        $this->reposts()->unrepostReply($this->owner, $this->post(), self::REPLY_URI);

        self::assertSame(['resolve ' . self::REPLY_URI], $this->mastodon->calls);
    }

    public function testAnAddressThatIsNoPostOnTheEntrysNetworkIsRefusedBeforeAnyCall(): void
    {
        try {
            $this->reposts()->repostReply($this->owner, $this->post(), 'at://did:plc:ann/app.bsky.feed.post/1');
            self::fail('A Bluesky address must not be reposted through a Mastodon entry.');
        } catch (NotASocialPostException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    private function post(): Entry
    {
        $feed = new Feed(self::FEED_URL);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', self::STATUS_URL, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private static function address(): SocialPostAddressModel
    {
        return new SocialPostAddressModel(SocialNetwork::Mastodon, self::STATUS_URL);
    }

    private function reposts(): SocialReposts
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);

        return new SocialReposts(
            new SocialPostFinder(
                SocialFeeds::actingAccountChooser($this->entityManager, $accounts),
                $guard,
                $network,
            ),
            new PostReposting($guard, $network),
            $this->countsCache,
            $this->comments,
        );
    }
}
