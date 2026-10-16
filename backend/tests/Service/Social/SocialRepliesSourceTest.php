<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\RateLimit\Exception\RateLimitedException;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\ActingAccountChooser;
use App\Service\Social\ActingAccountGuard;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\ReplyThreadOrder;
use App\Service\Social\SocialRepliesSource;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeReplyThreadSource;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class SocialRepliesSourceTest extends DbTestCase
{
    use SeedsUsers;

    private const string STATUS_URL = 'https://mastodon.social/@Gargron/110';
    private const string FEED_URL = 'https://mastodon.social/@Gargron.rss';

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

        $answer = $this->source()->fetch($this->owner, $this->post());

        self::assertEquals(CommentsAnswerModel::stillGathering('the public thread'), $answer);
        self::assertSame(['inPublic ' . self::STATUS_URL], $this->threads->calls);
    }

    public function testWithAnAccountTheThreadIsTheAccounts(): void
    {
        $account = $this->mastodonAccount();
        $this->threads->answersAsAccount(CommentsAnswerModel::settled('the account thread'));

        $answer = $this->source()->fetch($this->owner, $this->post());

        self::assertEquals(CommentsAnswerModel::settled('the account thread'), $answer);
        self::assertSame(['asAccount ' . $account->getHandle() . ' ' . self::STATUS_URL], $this->threads->calls);
    }

    public function testAFailingAccountFallsBackToThePublicThread(): void
    {
        $account = $this->mastodonAccount();
        $this->threads->failAsAccountWith(new SocialNetworkUnreachableException('The instance is down.'));

        $answer = $this->source()->fetch($this->owner, $this->post());

        self::assertEquals(CommentsAnswerModel::settled('public thread'), $answer);
        self::assertSame(
            ['asAccount ' . $account->getHandle() . ' ' . self::STATUS_URL, 'inPublic ' . self::STATUS_URL],
            $this->threads->calls,
        );
    }

    public function testAThrottledAccountFallsBackToThePublicThread(): void
    {
        $this->mastodonAccount();
        $this->threads->failAsAccountWith(new SocialNetworkThrottledException('Fewer requests, please.', 30));

        $answer = $this->source()->fetch($this->owner, $this->post());

        self::assertEquals(CommentsAnswerModel::settled('public thread'), $answer);
    }

    public function testAThrottledNetworkSaysWhenToRetry(): void
    {
        $this->threads->failInPublicWith(new SocialNetworkThrottledException('Fewer requests, please.', 30));

        try {
            $this->source()->fetch($this->owner, $this->post());
            self::fail('A throttled network must say when to retry.');
        } catch (CommentsThrottledException $caught) {
            self::assertSame(30, $caught->retryAfterSeconds);
        }
    }

    public function testAThrottledNetworkThatNamesNoWaitIsRetriedInAMinute(): void
    {
        $this->threads->failInPublicWith(new SocialNetworkThrottledException('Fewer requests, please.'));

        try {
            $this->source()->fetch($this->owner, $this->post());
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

        $this->source()->fetch($this->owner, $this->post());
    }

    public function testAnUnreachablePublicThreadIsUnavailable(): void
    {
        $this->threads->failInPublicWith(new SocialNetworkUnreachableException('The origin is down.'));

        $this->expectException(CommentsUnavailableException::class);

        $this->source()->fetch($this->owner, $this->post());
    }

    public function testTheAudienceSeparatesAccountsFromThePublic(): void
    {
        $post = $this->post();
        $publicAudience = $this->source()->audienceOf($this->owner, $post);
        $account = $this->mastodonAccount();

        $accountAudience = $this->source()->audienceOf($this->owner, $post);

        self::assertSame('mastodon ' . self::STATUS_URL . ' public', $publicAudience);
        self::assertSame('mastodon ' . self::STATUS_URL . ' account:' . $account->requireId(), $accountAudience);
    }

    public function testEachFetchSpendsTheSocialRepliesBudget(): void
    {
        $post = $this->post();
        for ($fetch = 0; $fetch < self::REPLIES_BUDGET; ++$fetch) {
            $this->source()->fetch($this->owner, $post);
        }

        $this->expectException(RateLimitedException::class);

        try {
            $this->source()->fetch($this->owner, $post);
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

        $page = $this->source()->read($this->post(), 'the thread');

        self::assertSame(['newer', 'older'], array_map(
            static fn (EntryCommentModel $comment): string => $comment->html,
            $page->comments,
        ));
        self::assertSame(['repliesIn ' . self::STATUS_URL . ' the thread'], $this->threads->calls);
    }

    private static function reply(string $text, string $publishedAt): ReplyNode
    {
        $at = new \DateTimeImmutable($publishedAt);

        return new ReplyNode(
            $at,
            static fn (): EntryCommentModel => new EntryCommentModel(
                new CommentAuthorModel('Ann'),
                null,
                $at,
                $text,
                false,
            ),
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
            new ActingAccountChooser(SocialFeeds::repository($this->entityManager), $accounts),
            new ActingAccountGuard($this->entityManager),
            new ServiceLocator(['mastodon' => static fn (): FakeReplyThreadSource => $threads]),
            $order,
            new RateLimitGuard(new MockClock('2026-10-16 10:00:00')),
            $limiter,
        );
    }
}
