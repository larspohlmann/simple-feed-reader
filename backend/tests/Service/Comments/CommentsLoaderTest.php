<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Service\Comments\CommentsLoader;
use App\Service\Comments\CommentsStatus;
use App\Service\Comments\EntryComment;
use App\Service\Discussion\Discussion;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Service\Fetch\HostThrottle;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class CommentsLoaderTest extends KernelTestCase
{
    private const string THREAD = 'https://www.reddit.com/r/PHP/comments/1woq4he/what_is_your_php_stack_2026/';
    private const string FEED = self::THREAD . '.rss';
    private const string THREAD_GUID = 't3_1woq4he';
    private const string WORDPRESS_POST = 'https://blog.example.org/2026/09/hello-world/';

    private StubFeedFetcher $fetcher;
    private HostThrottle $throttle;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fetcher = new StubFeedFetcher();
        $clock = new MockClock('2026-09-24 12:00:00');
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $clock), $clock);
    }

    /** @return list<EntryComment> */
    private function loadWordPressComments(): array
    {
        $feed = self::WORDPRESS_POST . 'feed/';
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/wordpress/post-comments.rss');
        $this->fetcher->willReturn($feed, FetchResponse::fetched($feed, false, $xml, null, null));
        $entry = self::entry(guid: 'https://blog.example.org/?p=7');
        $entry->setDiscussion(
            Discussion::withCommentsFeed(self::WORDPRESS_POST . '#comments', $feed, CommentsLoad::Manual),
        );

        return $this->loader()->load($entry)->comments;
    }

    private function loader(): CommentsLoader
    {
        $container = self::getContainer();
        $container->set(FeedFetcherInterface::class, $this->fetcher);
        $container->set(HostThrottle::class, $this->throttle);
        $loader = $container->get(CommentsLoader::class);
        self::assertInstanceOf(CommentsLoader::class, $loader);

        return $loader;
    }

    private function serveRedditThread(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../../Fixtures/reddit/thread-comments.atom');
        $this->fetcher->willReturn(self::FEED, FetchResponse::fetched(self::FEED, false, $xml, null, null));
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
        $entry->setAuthor($author);
        $entry->setDiscussion(Discussion::withCommentsFeed(self::THREAD, self::FEED, CommentsLoad::Auto));

        return $entry;
    }

    public function testParsesCommentsAndDropsThePostItself(): void
    {
        $this->serveRedditThread();

        $result = $this->loader()->load(self::entry());

        self::assertSame(CommentsStatus::Ok, $result->status);
        self::assertNotSame([], $result->comments);
        self::assertNotContains(
            self::THREAD,
            array_map(static fn (EntryComment $comment) => $comment->url, $result->comments),
        );
    }

    public function testKeepsEveryWordPressComment(): void
    {
        $comments = $this->loadWordPressComments();

        self::assertSame(
            [self::WORDPRESS_POST . '#comment-12', self::WORDPRESS_POST . '#comment-13'],
            array_map(static fn (EntryComment $comment) => $comment->url, $comments),
        );
    }

    public function testStripsScriptsFromCommentBodies(): void
    {
        $bodies = array_map(static fn (EntryComment $comment) => $comment->html, $this->loadWordPressComments());

        self::assertStringContainsString('Agreed.', $bodies[1]);
        self::assertStringNotContainsString('<script', $bodies[1]);
    }

    public function testMarksTheEntryAuthorsComments(): void
    {
        $this->serveRedditThread();

        $result = $this->loader()->load(self::entry('/u/Background_Lie11'));

        $byAuthor = array_filter($result->comments, static fn ($c): bool => $c->byEntryAuthor);
        self::assertNotSame([], $byAuthor);
    }

    public function testLeavesOtherCommentersUnmarked(): void
    {
        $this->serveRedditThread();

        $result = $this->loader()->load(self::entry('/u/Background_Lie11'));

        $byOtherCommenter = array_filter(
            $result->comments,
            static fn ($c): bool => $c->author === '/u/WesamMikhail',
        );
        self::assertNotSame([], $byOtherCommenter);
        foreach ($byOtherCommenter as $comment) {
            self::assertFalse($comment->byEntryAuthor);
        }
    }

    public function testAnEntryWithNoAuthorMarksNoComment(): void
    {
        $this->serveRedditThread();

        $result = $this->loader()->load(self::entry(null));

        $byAuthor = array_filter($result->comments, static fn ($c): bool => $c->byEntryAuthor);
        self::assertSame([], $byAuthor);
    }

    public function testAnotherEntrysThreadKeepsEveryParsedItem(): void
    {
        $this->serveRedditThread();

        $result = $this->loader()->load(self::entry(guid: 't3_another'));

        self::assertContains(self::THREAD, array_map(static fn ($c) => $c->url, $result->comments));
    }

    public function testA429IsThrottledAndRemembered(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedThrottledException('429', null));
        $loader = $this->loader();

        $first = $loader->load(self::entry());
        $second = $loader->load(self::entry());

        self::assertSame(CommentsStatus::Throttled, $first->status);
        self::assertSame(60, $first->retryAfter);
        self::assertSame(CommentsStatus::Throttled, $second->status);
        self::assertCount(1, $this->fetcher->fetchedUrls);
    }

    public function testAZeroRetryAfterIsClampedToTheFloor(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedThrottledException('429', 0));

        $result = $this->loader()->load(self::entry());

        self::assertSame(CommentsStatus::Throttled, $result->status);
        self::assertSame(60, $result->retryAfter);
    }

    public function testAHugeRetryAfterIsClampedToTheCeiling(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedThrottledException('429', 99_999_999));

        $result = $this->loader()->load(self::entry());

        self::assertSame(CommentsStatus::Throttled, $result->status);
        self::assertSame(86_400, $result->retryAfter);
    }

    public function testAKnownThrottleSkipsTheRequest(): void
    {
        $this->throttle->record('https://www.reddit.com/r/PHP/.rss', 90);

        $result = $this->loader()->load(self::entry());

        self::assertSame(CommentsStatus::Throttled, $result->status);
        self::assertSame(90, $result->retryAfter);
        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAFetchFailureIsFailed(): void
    {
        $this->fetcher->willThrow(self::FEED, new FeedUnreachableException('HTTP 500', statusCode: 500));

        self::assertSame(CommentsStatus::Failed, $this->loader()->load(self::entry())->status);
    }

    public function testUnparseableBodyIsFailed(): void
    {
        $this->fetcher->willReturn(
            self::FEED,
            FetchResponse::fetched(self::FEED, false, /** @lang TEXT */ '<html>nope', null, null),
        );

        self::assertSame(CommentsStatus::Failed, $this->loader()->load(self::entry())->status);
    }
}
