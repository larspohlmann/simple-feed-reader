<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Comments\CommentsCache;
use App\Service\Comments\CommentsLoader;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\PostLiking;
use App\Service\Social\SocialCommentLikes;
use App\Service\Social\SocialPostFinder;
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

final class SocialCommentLikesTest extends DbTestCase
{
    use AssertsRefusal;
    use SeedsUsers;

    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';
    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string REPLY_URI = 'https://hachyderm.io/users/ann/statuses/200';

    private FakeMastodonNetwork $mastodon;
    private FakeCommentsSource $thread;
    private CommentsLoader $comments;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mastodon = new FakeMastodonNetwork();
        $this->thread = new FakeCommentsSource('thread');
        $clock = new MockClock('2026-10-18 12:00:00');
        $cache = new CommentsCache(new ArrayAdapter(clock: $clock), 7200, 60);
        $this->comments = new CommentsLoader([$this->thread], $cache, $clock);
        $this->owner = $this->user('social-comment-likes@example.test');
    }

    public function testALikeResolvesTheReplyOnTheAccountAndLikesIt(): void
    {
        $this->mastodonAccount();

        $this->likes()->like($this->owner, $this->post(), self::REPLY_URI);

        self::assertSame(['resolve ' . self::REPLY_URI, 'like 110'], $this->mastodon->calls);
    }

    public function testAnUnlikeTakesBackTheLikeTheNetworkReports(): void
    {
        $this->mastodonAccount();
        $this->mastodon->knowsTheLike();

        $this->likes()->unlike($this->owner, $this->post(), self::REPLY_URI);

        self::assertSame(['resolve ' . self::REPLY_URI, 'unlike 110'], $this->mastodon->calls);
    }

    public function testUnlikingAReplyNeverLikedOnlyLooksItUp(): void
    {
        $this->mastodonAccount();

        $this->likes()->unlike($this->owner, $this->post(), self::REPLY_URI);

        self::assertSame(['resolve ' . self::REPLY_URI], $this->mastodon->calls);
    }

    public function testALikeMakesTheNextLoadAskForTheThreadAgain(): void
    {
        $this->mastodonAccount();
        $entry = $this->post();
        $this->comments->load($this->owner, $entry);

        $this->likes()->like($this->owner, $entry, self::REPLY_URI);
        $this->comments->load($this->owner, $entry);

        self::assertCount(2, $this->thread->fetches);
    }

    public function testAnUnlikeMakesTheNextLoadAskForTheThreadAgain(): void
    {
        $this->mastodonAccount();
        $entry = $this->post();
        $this->comments->load($this->owner, $entry);

        $this->likes()->unlike($this->owner, $entry, self::REPLY_URI);
        $this->comments->load($this->owner, $entry);

        self::assertCount(2, $this->thread->fetches);
    }

    public function testAFailedLikeKeepsTheCachedThread(): void
    {
        $this->mastodonAccount();
        $entry = $this->post();
        $this->comments->load($this->owner, $entry);
        $this->mastodon->failWith(new SocialNetworkUnreachableException('down'));

        $this->assertRefused(
            fn () => $this->likes()->like($this->owner, $entry, self::REPLY_URI),
            SocialNetworkUnreachableException::class,
            'An unreachable network must reach the caller.',
        );
        $this->comments->load($this->owner, $entry);

        self::assertCount(1, $this->thread->fetches);
    }

    public function testAnAddressThatIsNoPostOnTheEntrysNetworkIsRefusedBeforeAnyCall(): void
    {
        $this->mastodonAccount();

        try {
            $this->likes()->like($this->owner, $this->post(), 'at://did:plc:ann/app.bsky.feed.post/1');
            self::fail('A Bluesky address must not be liked through a Mastodon entry.');
        } catch (NotASocialPostException) {
            self::assertSame([], $this->mastodon->calls);
        }
    }

    public function testAReplyOfAnOrdinaryArticleIsRefused(): void
    {
        $this->mastodonAccount();

        $this->expectException(NotASocialPostException::class);

        $this->likes()->like($this->owner, $this->entryOn('https://example.com/feed.xml'), self::REPLY_URI);
    }

    private function mastodonAccount(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
    }

    private function post(): Entry
    {
        return $this->entryOn(self::FEED_URL);
    }

    private function entryOn(string $feedUrl): Entry
    {
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $now = new \DateTimeImmutable('2026-10-18 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', self::STATUS_URL, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function likes(): SocialCommentLikes
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $guard = new ActingAccountGuard($this->entityManager);
        $mastodon = $this->mastodon;
        $network = new ServiceLocator(['mastodon' => static fn (): FakeMastodonNetwork => $mastodon]);

        return new SocialCommentLikes(
            new SocialPostFinder(
                new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts),
                $guard,
                $network,
            ),
            new PostLiking($guard, $network),
            $this->comments,
        );
    }
}
