<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Comments\Model\CommentPostModel;
use App\Service\Comments\Model\CommentViewerModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\RateLimit\Exception\RateLimitedException;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\ReplyThreadOrder;
use App\Service\Social\SocialRepliesSource;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeReplyThreadSource;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class SocialRepliesSourceTest extends DbTestCase
{
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';
    private const string FOLDED_REPLY_URL = 'https://mastodon.social/@Gargron/111';

    /** Must match framework.rate_limiter.social_replies.limit in rate_limiter.yaml. */
    private const int REPLIES_BUDGET = 120;

    private FakeReplyThreadSource $threads;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        $this->threads = new FakeReplyThreadSource();
        $this->owner = $this->user('social-replies-source@example.test');
    }

    public function testServesASocialPostAndNoPlainArticle(): void
    {
        self::assertTrue($this->source()->serves($this->post()));
        self::assertFalse($this->source()->serves($this->entryOn('https://example.com/feed.xml')));
    }

    public function testWithoutAnAccountTheThreadIsPublic(): void
    {
        $this->threads->answersInPublic(CommentsAnswerModel::stillGathering('the public thread'));

        $answer = $this->fetch($this->post());

        self::assertEquals(CommentsAnswerModel::stillGathering('the public thread'), $answer);
        self::assertSame(['inPublic ' . self::STATUS_URL], $this->threads->calls);
    }

    public function testWithAnAccountTheThreadIsTheAccounts(): void
    {
        $account = $this->mastodonAccount();
        $this->threads->answersAsAccount(CommentsAnswerModel::settled('the account thread'));

        $answer = $this->fetch($this->post());

        self::assertEquals(CommentsAnswerModel::settled('the account thread'), $answer);
        self::assertSame(['asAccount ' . $account->getHandle() . ' ' . self::STATUS_URL], $this->threads->calls);
    }

    public function testTheFetchActsAsTheAccountTheAudienceNamed(): void
    {
        $source = $this->source();
        $request = new CommentsRequest($source, $this->owner, $this->post());
        $this->mastodonAccount();

        $answer = $source->fetch($request);

        self::assertEquals(CommentsAnswerModel::settled('public thread'), $answer);
        self::assertSame(['inPublic ' . self::STATUS_URL], $this->threads->calls);
    }

    #[DataProvider('accountFailures')]
    public function testAFailingAccountFallsBackToThePublicThread(\Throwable $failure): void
    {
        $account = $this->mastodonAccount();
        $this->threads->failAsAccountWith($failure);

        $answer = $this->fetch($this->post());

        self::assertEquals(CommentsAnswerModel::settled('public thread'), $answer);
        self::assertSame(
            ['asAccount ' . $account->getHandle() . ' ' . self::STATUS_URL, 'inPublic ' . self::STATUS_URL],
            $this->threads->calls,
        );
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function accountFailures(): iterable
    {
        yield 'needs reconnect' => [new SocialAccountNeedsReconnectException('Sign in again.')];
        yield 'credentials rejected' => [new SocialCredentialsRejectedException('The token was revoked.')];
        yield 'token scope missing' => [new SocialTokenScopeMissingException('The token cannot read.', 'read')];
        yield 'post refused' => [new SocialPostRefusedException('The instance will not show the post.')];
        yield 'post not found' => [new SocialPostNotFoundException('Gone from the instance.')];
        yield 'unreachable' => [new SocialNetworkUnreachableException('The instance is down.')];
        yield 'throttled' => [new SocialNetworkThrottledException('Fewer requests, please.', 30)];
    }

    public function testAThrottledPublicNetworkIsRefusedBeforeTheBudgetIsSpent(): void
    {
        $post = $this->post();
        $this->threads->throttlePublicThreadFor(30);
        for ($fetch = 0; $fetch < self::REPLIES_BUDGET; ++$fetch) {
            try {
                $this->fetch($post);
                self::fail('A throttled public network must be refused.');
            } catch (CommentsThrottledException $caught) {
                self::assertSame(30, $caught->retryAfterSeconds);
            }
        }
        $this->threads->throttlePublicThreadFor(0);

        $answer = $this->fetch($post);

        self::assertEquals(CommentsAnswerModel::settled('public thread'), $answer);
        self::assertSame(['inPublic ' . self::STATUS_URL], $this->threads->calls);
    }

    public function testAThrottledPublicNetworkDoesNotHoldBackTheAccount(): void
    {
        $this->mastodonAccount();
        $this->threads->throttlePublicThreadFor(30);

        $answer = $this->fetch($this->post());

        self::assertEquals(CommentsAnswerModel::settled('account thread'), $answer);
    }

    public function testAThrottledNetworkSaysWhenToRetry(): void
    {
        $this->threads->failInPublicWith(new SocialNetworkThrottledException('Fewer requests, please.', 30));

        try {
            $this->fetch($this->post());
            self::fail('A throttled network must say when to retry.');
        } catch (CommentsThrottledException $caught) {
            self::assertSame(30, $caught->retryAfterSeconds);
        }
    }

    public function testAThrottledNetworkThatNamesNoWaitIsRetriedInAMinute(): void
    {
        $this->threads->failInPublicWith(new SocialNetworkThrottledException('Fewer requests, please.'));

        try {
            $this->fetch($this->post());
            self::fail('A throttled network must say when to retry.');
        } catch (CommentsThrottledException $caught) {
            self::assertSame(60, $caught->retryAfterSeconds);
        }
    }

    public function testAThreadNowhereToBeFoundIsUnavailable(): void
    {
        $this->mastodonAccount();
        $this->threads->failAsAccountWith(new SocialPostNotFoundException('Gone from the instance.'));
        $this->threads->failInPublicWith(new SocialPostNotFoundException('Gone from the origin.'));

        $this->expectException(CommentsUnavailableException::class);

        $this->fetch($this->post());
    }

    public function testAnUnreachablePublicThreadIsUnavailable(): void
    {
        $this->threads->failInPublicWith(new SocialNetworkUnreachableException('The origin is down.'));

        $this->expectException(CommentsUnavailableException::class);

        $this->fetch($this->post());
    }

    public function testAnEntryThatIsNoSocialPostHasNoThreadToFetch(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->fetch($this->entryOn('https://example.com/feed.xml'));
    }

    public function testAnEntryThatIsNoSocialPostHasNoThreadToRead(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->read($this->entryOn('https://example.com/feed.xml'));
    }

    public function testTheAudienceSeparatesAccountsFromThePublic(): void
    {
        $post = $this->post();
        $publicAudience = $this->source()->audienceOf($this->owner, $post)->scope;
        $account = $this->mastodonAccount();

        $accountAudience = $this->source()->audienceOf($this->owner, $post)->scope;

        self::assertSame('mastodon ' . self::STATUS_URL . ' public', $publicAudience);
        self::assertSame('mastodon ' . self::STATUS_URL . ' account:' . $account->requireId(), $accountAudience);
    }

    public function testEachFetchSpendsTheSocialRepliesBudget(): void
    {
        $post = $this->post();
        for ($fetch = 0; $fetch < self::REPLIES_BUDGET; ++$fetch) {
            $this->fetch($post);
        }

        $this->expectException(RateLimitedException::class);

        try {
            $this->fetch($post);
        } finally {
            self::assertCount(self::REPLIES_BUDGET, $this->threads->calls);
        }
    }

    public function testEachFetchAsTheAccountSpendsTheSocialRepliesBudgetToo(): void
    {
        $this->mastodonAccount();
        $post = $this->post();
        for ($fetch = 0; $fetch < self::REPLIES_BUDGET; ++$fetch) {
            $this->fetch($post);
        }

        $this->expectException(RateLimitedException::class);

        try {
            $this->fetch($post);
        } finally {
            self::assertCount(self::REPLIES_BUDGET, $this->threads->calls);
        }
    }

    public function testReadingOrdersTheTree(): void
    {
        $this->threads->readsReplies([
            self::reply('older', '2026-10-16 08:00:00'),
            self::reply('newer', '2026-10-16 09:00:00'),
        ]);

        $page = $this->read($this->post());

        self::assertSame(['newer', 'older'], array_map(
            static fn (EntryCommentModel $comment): string => $comment->html,
            $page->comments,
        ));
        self::assertSame(['repliesIn ' . self::STATUS_URL . ' the thread'], $this->threads->calls);
    }

    public function testAFoldedReplyStillShowsWithItsChildren(): void
    {
        $entry = $this->post();
        $this->entityManager->persist(new ThreadPost(
            $entry,
            1,
            new ThreadPostContent(self::FOLDED_REPLY_URL, null, '<p>folded</p>', []),
        ));
        $this->entityManager->flush();
        $this->threads->readsReplies([
            self::reply('folded', '2026-10-16 08:00:00', self::FOLDED_REPLY_URL)
                ->withReplies([self::reply('child', '2026-10-16 09:00:00')]),
            self::reply('other', '2026-10-16 07:00:00'),
        ]);

        $page = $this->read($entry);

        self::assertSame(['folded', 'child', 'other'], array_map(
            static fn (EntryCommentModel $comment): string => $comment->html,
            $page->comments,
        ));
    }

    public function testRepliesAConnectedAccountOfTheViewerWroteAreMarkedAsItsOwn(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->owner));
        $broken = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'bea@mastodon.example'),
        );
        $broken->markNeedsReconnect();
        $this->entityManager->flush();
        $this->threads->readsReplies([
            self::postedReply('mine', '2026-10-16 09:00:00', 'Alice@Mastodon.Example'),
            self::postedReply('broken', '2026-10-16 08:00:00', 'bea@mastodon.example'),
            self::postedReply('theirs', '2026-10-16 07:00:00', 'ann@hachyderm.io'),
            self::reply('no post', '2026-10-16 06:00:00'),
        ]);

        $page = $this->read($this->post());

        self::assertSame([true, false, false, null], array_map(
            static fn (EntryCommentModel $comment): ?bool => $comment->post?->viewer->isOwn,
            $page->comments,
        ));
    }

    private function read(Entry $entry): CommentsPageModel
    {
        $source = $this->source();

        return $source->read(new CommentsRequest($source, $this->owner, $entry), 'the thread');
    }

    private static function postedReply(string $text, string $publishedAt, string $author): ReplyNode
    {
        $post = new CommentPostModel(
            'https://hachyderm.io/users/x/statuses/' . $text,
            new CommentCountsModel(0, 0, 0, null),
            new CommentViewerModel(false, false, true),
            $author,
        );

        return new ReplyNode(
            new \DateTimeImmutable($publishedAt),
            static fn (?\DateTimeImmutable $at): EntryCommentModel => new EntryCommentModel(
                new CommentAuthorModel('Ann'),
                null,
                $at,
                $text,
                false,
                post: $post,
            ),
            [],
            null,
            false,
        );
    }

    private static function reply(string $text, string $publishedAt, ?string $url = null): ReplyNode
    {
        $at = new \DateTimeImmutable($publishedAt);

        return new ReplyNode(
            $at,
            static fn (?\DateTimeImmutable $publishedAt): EntryCommentModel => new EntryCommentModel(
                new CommentAuthorModel('Ann'),
                null,
                $publishedAt,
                $text,
                false,
            ),
            [],
            $url,
            false,
        );
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
        $now = new \DateTimeImmutable('2026-10-16 10:00:00');
        $entry = new Entry($feed, 'tag:mastodon.social,2026:110', self::STATUS_URL, 'Post', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function fetch(Entry $entry): CommentsAnswerModel
    {
        $source = $this->source();

        return $source->fetch(new CommentsRequest($source, $this->owner, $entry));
    }

    private function source(): SocialRepliesSource
    {
        /** @var SocialAccountRepository $accounts */
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        $threads = $this->threads;
        $order = self::getContainer()->get(ReplyThreadOrder::class);
        self::assertInstanceOf(ReplyThreadOrder::class, $order);
        $limiter = self::getContainer()->get('limiter.social_replies');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return new SocialRepliesSource(
            SocialFeeds::actingAccountChooser($this->entityManager, $accounts),
            new ActingAccountGuard($this->entityManager),
            new ServiceLocator(['mastodon' => static fn (): FakeReplyThreadSource => $threads]),
            $order,
            new RateLimitGuard(new MockClock('2026-10-16 10:00:00')),
            $limiter,
            $accounts,
        );
    }
}
