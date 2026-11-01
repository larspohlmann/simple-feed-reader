<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\CommentsSource;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Entity\User;
use App\Enum\CommentsLoad;
use App\Service\Comments\CommentsSource\CommentsFeedSource;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\RateLimit\Exception\RateLimitedException;
use App\Tests\Support\StubFeedFetcher;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class CommentsFeedSourceTest extends KernelTestCase
{
    private const string THREAD = 'https://www.reddit.com/r/PHP/comments/1woq4he/what_is_your_php_stack_2026/';
    private const string FEED = self::THREAD . '.rss';
    private const string THREAD_GUID = 't3_1woq4he';
    private const string WORDPRESS_POST = 'https://blog.example.org/2026/09/hello-world/';

    /** Must match framework.rate_limiter.comments.limit in rate_limiter.yaml. */
    private const int COMMENTS_BUDGET = 30;

    private StubFeedFetcher $fetcher;
    private MockClock $clock;
    private HostThrottle $throttle;
    private User $user;
    private CommentsFeedSource $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        $this->fetcher = new StubFeedFetcher();
        $this->clock = new MockClock('2026-09-24 12:00:00');
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->user = new User('reader@example.com', $this->clock->now());
        $container = self::getContainer();
        $container->set(FeedFetcherInterface::class, $this->fetcher);
        $container->set(HostThrottle::class, $this->throttle);
        $source = $container->get(CommentsFeedSource::class);
        self::assertInstanceOf(CommentsFeedSource::class, $source);
        $this->source = $source;
    }

    private function fetch(Entry $entry): CommentsAnswerModel
    {
        return $this->source->fetch($this->requestFor($entry));
    }

    /** @return list<EntryCommentModel> */
    private function comments(Entry $entry): array
    {
        return $this->source->read($this->requestFor($entry), $this->fetch($entry)->body)->comments;
    }

    /** @return list<EntryCommentModel> */
    private function wordPressComments(): array
    {
        $feed = self::WORDPRESS_POST . 'feed/';
        $xml = (string) file_get_contents(__DIR__ . '/../../../Fixtures/wordpress/post-comments.rss');
        $this->fetcher->willReturn($feed, FetchResponseModel::fetched($feed, false, $xml, null, null));
        $entry = self::entry(guid: 'https://blog.example.org/?p=7');
        $entry->setDiscussion(
            Discussion::withCommentsFeed(self::WORDPRESS_POST . '#comments', $feed, CommentsLoad::Manual),
        );

        return $this->comments($entry);
    }

    private function serveRedditThread(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../../Fixtures/reddit/thread-comments.atom');
        $this->fetcher->willReturn(self::FEED, FetchResponseModel::fetched(self::FEED, false, $xml, null, null));
    }

    private function throttledFetch(): CommentsThrottledException
    {
        try {
            $this->fetch(self::entry());
        } catch (CommentsThrottledException $exception) {
            return $exception;
        }

        self::fail('The fetch was not throttled.');
    }

    private static function entry(?string $author = '/u/Background_Lie11', string $guid = self::THREAD_GUID): Entry
    {
        $entry = new Entry(
            new Feed('https://www.reddit.com/r/PHP/.rss'),
            $guid,
            null,
            'Stack',
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
        );
        $entry->setAuthor(new EntryAuthor($author));
        $entry->setDiscussion(Discussion::withCommentsFeed(self::THREAD, self::FEED, CommentsLoad::Auto));

        return $entry;
    }

    public function testServesAnEntryWithACommentsFeed(): void
    {
        self::assertTrue($this->source->serves(self::entry()));
    }

    public function testServesNoEntryWithoutOne(): void
    {
        $entry = self::entry();
        $entry->setDiscussion(Discussion::of(self::THREAD, null, CommentsLoad::Auto));

        self::assertFalse($this->source->serves($entry));
    }

    public function testTheAudienceIsTheFeedForEveryReader(): void
    {
        $other = new User('other@example.com', $this->clock->now());

        self::assertSame(self::FEED, $this->source->audienceOf($this->user, self::entry())->scope);
        self::assertSame(self::FEED, $this->source->audienceOf($other, self::entry())->scope);
    }

    public function testParsesCommentsAndDropsThePostItself(): void
    {
        $this->serveRedditThread();

        $comments = $this->comments(self::entry());

        self::assertNotSame([], $comments);
        self::assertNotContains(
            self::THREAD,
            array_map(static fn (EntryCommentModel $comment) => $comment->url, $comments),
        );
    }

    public function testKeepsEveryWordPressComment(): void
    {
        self::assertSame(
            [self::WORDPRESS_POST . '#comment-12', self::WORDPRESS_POST . '#comment-13'],
            array_map(static fn (EntryCommentModel $comment) => $comment->url, $this->wordPressComments()),
        );
    }

    public function testStripsScriptsFromCommentBodies(): void
    {
        $bodies = array_map(static fn (EntryCommentModel $comment) => $comment->html, $this->wordPressComments());

        self::assertStringContainsString('Agreed.', $bodies[1]);
        self::assertStringNotContainsString('<script', $bodies[1]);
    }

    public function testMarksTheEntryAuthorsComments(): void
    {
        $this->serveRedditThread();

        $comments = $this->comments(self::entry('/u/Background_Lie11'));

        self::assertNotSame([], array_filter($comments, static fn ($comment): bool => $comment->byEntryAuthor));
    }

    public function testLeavesOtherCommentersUnmarked(): void
    {
        $this->serveRedditThread();

        $comments = $this->comments(self::entry('/u/Background_Lie11'));

        $byOtherCommenter = array_filter(
            $comments,
            static fn ($comment): bool => $comment->author->name === '/u/WesamMikhail',
        );
        self::assertNotSame([], $byOtherCommenter);
        foreach ($byOtherCommenter as $comment) {
            self::assertFalse($comment->byEntryAuthor);
        }
    }

    public function testMarksNoCommentWhenTheEntryHasNoAuthor(): void
    {
        $this->serveRedditThread();

        $comments = $this->comments(self::entry(null));

        self::assertSame([], array_filter($comments, static fn ($comment): bool => $comment->byEntryAuthor));
    }

    public function testAnotherEntrysThreadKeepsEveryParsedItem(): void
    {
        $this->serveRedditThread();

        $comments = $this->comments(self::entry(guid: 't3_another'));

        self::assertContains(self::THREAD, array_map(static fn ($comment) => $comment->url, $comments));
    }

    public function testAFeedCommentCarriesItsAuthorsNameAndProfile(): void
    {
        $this->serveRedditThread();

        $first = $this->comments(self::entry())[0];

        self::assertNotNull($first->author->name);
        self::assertNotNull($first->author->profileUrl);
        self::assertSame(0, $first->depth);
    }

    public function testAFeedAnswerIsSettled(): void
    {
        $this->serveRedditThread();

        self::assertFalse($this->fetch(self::entry())->isStillGathering);
    }

    public function testAFeedAskingForFewerRequestsIsRecordedAndReported(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedThrottledException('429', null));

        $first = $this->throttledFetch();
        $second = $this->throttledFetch();

        self::assertSame(60, $first->retryAfterSeconds);
        self::assertSame(60, $second->retryAfterSeconds);
        self::assertCount(1, $this->fetcher->fetchedUrls);
    }

    public function testAZeroRetryAfterIsClampedToTheFloor(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedThrottledException('429', 0));

        self::assertSame(60, $this->throttledFetch()->retryAfterSeconds);
    }

    public function testAHugeRetryAfterIsClampedToTheCeiling(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedThrottledException('429', 99_999_999));

        self::assertSame(86_400, $this->throttledFetch()->retryAfterSeconds);
    }

    public function testAThrottledHostIsReportedWithoutAFetch(): void
    {
        $this->throttle->record('https://www.reddit.com/r/PHP/.rss', 90);

        self::assertSame(90, $this->throttledFetch()->retryAfterSeconds);
        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAThrottledHostSpendsNoBudget(): void
    {
        $this->throttle->record('https://www.reddit.com/r/PHP/.rss', 90);
        for ($attempt = 0; $attempt <= self::COMMENTS_BUDGET; ++$attempt) {
            $this->throttledFetch();
        }
        $this->clock->sleep(91);
        $this->serveRedditThread();

        $answer = $this->fetch(self::entry());

        self::assertNotSame('', $answer->body);
    }

    public function testAnUnreachableFeedIsUnavailable(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedUnreachableException('HTTP 500', statusCode: 500));

        $this->expectException(CommentsUnavailableException::class);

        $this->fetch(self::entry());
    }

    public function testAnEntryWithoutAFeedIsUnavailableToFetch(): void
    {
        $entry = self::entry();
        $entry->setDiscussion(Discussion::of(self::THREAD, null, CommentsLoad::Auto));

        $this->expectException(CommentsUnavailableException::class);

        $this->fetch($entry);
    }

    public function testAnUnparsableAnswerIsUnavailableWhenRead(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->source->read($this->requestFor(self::entry()), /** @lang TEXT */ '<html>nope');
    }

    public function testEachFetchSpendsTheReadersCommentsBudget(): void
    {
        $this->serveRedditThread();
        for ($fetch = 0; $fetch < self::COMMENTS_BUDGET; ++$fetch) {
            $this->fetch(self::entry());
        }

        $this->expectException(RateLimitedException::class);

        try {
            $this->fetch(self::entry());
        } finally {
            self::assertCount(self::COMMENTS_BUDGET, $this->fetcher->fetchedUrls);
        }
    }

    private function requestFor(Entry $entry): CommentsRequest
    {
        return new CommentsRequest($this->source, $this->user, $entry);
    }
}
