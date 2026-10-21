<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Entity\User;
use App\Enum\FeedPlatform;
use App\Service\Comments\CommentsSource\SubstackCommentsSource;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\RateLimit\Exception\RateLimitedException;
use App\Tests\Support\StubFeedFetcher;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SubstackCommentsSourceTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/substack';
    private const string POST = 'https://joe.substack.com/p/one-post';
    private const string POST_API = 'https://joe.substack.com/api/v1/posts/one-post';
    private const string COMMENTS_API =
        'https://joe.substack.com/api/v1/post/4242/comments?all_comments=true&sort=best_first';

    /** Must match framework.rate_limiter.comments.limit in rate_limiter.yaml. */
    private const int COMMENTS_BUDGET = 30;

    private StubFeedFetcher $fetcher;
    private MockClock $clock;
    private User $user;
    private SubstackCommentsSource $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        $this->fetcher = new StubFeedFetcher();
        $this->clock = new MockClock('2026-10-21 12:00:00');
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->user = new User('reader@example.com', $this->clock->now());
        $container = self::getContainer();
        $container->set(FeedFetcherInterface::class, $this->fetcher);
        $container->set(HostThrottle::class, $throttle);
        $source = $container->get(SubstackCommentsSource::class);
        self::assertInstanceOf(SubstackCommentsSource::class, $source);
        $this->source = $source;
    }

    private static function entry(?string $author = 'Joe Writer'): Entry
    {
        $feed = new Feed('https://joe.substack.com/feed');
        $feed->recordPlatform(FeedPlatform::Substack);
        $now = new \DateTimeImmutable();
        $entry = new Entry($feed, self::POST, self::POST, 'One post', $now, $now);
        $entry->setAuthor(new EntryAuthor($author));

        return $entry;
    }

    private function servePost(): void
    {
        $this->fetcher->willReturnBody(self::POST_API, (string) file_get_contents(self::FIXTURES . '/post.json'));
        $this->fetcher->willReturnBody(
            self::COMMENTS_API,
            (string) file_get_contents(self::FIXTURES . '/post-comments.json'),
        );
    }

    /** @return list<EntryCommentModel> */
    private function comments(): array
    {
        $this->servePost();
        $entry = self::entry();
        $answer = $this->source->fetch(new CommentsRequest($this->source, $this->user, $entry));

        return $this->source->read($entry, $answer->body)->comments;
    }

    public function testServesAPostOfASubstack(): void
    {
        self::assertTrue($this->source->serves(self::entry()));
    }

    public function testServesNoEntryOfAnotherFeed(): void
    {
        $entry = self::entry();
        $entry->getFeed()->recordPlatform(null);

        self::assertFalse($this->source->serves($entry));
    }

    public function testEveryReaderOfAPostSharesItsAnswer(): void
    {
        $other = new User('other@example.com', $this->clock->now());

        self::assertSame(self::POST, $this->source->audienceOf($this->user, self::entry())->scope);
        self::assertSame(self::POST, $this->source->audienceOf($other, self::entry())->scope);
    }

    public function testFlattensTheTreeDepthFirstAndDropsDeletedComments(): void
    {
        self::assertSame(
            [[self::POST . '/comment/11', 0], [self::POST . '/comment/12', 1], [self::POST . '/comment/14', 0]],
            array_map(
                static fn (EntryCommentModel $comment): array => [$comment->url, $comment->depth],
                $this->comments(),
            ),
        );
    }

    public function testACommenterIsNamedLinkedAndPictured(): void
    {
        $author = $this->comments()[0]->author;

        self::assertSame('Ada Reader', $author->name);
        self::assertSame('@adareader', $author->handle);
        self::assertSame('https://substack.com/@adareader', $author->profileUrl);
        self::assertSame('https://substackcdn.com/image/fetch/ada.png', $author->avatarUrl);
    }

    public function testABodyIsEscapedParagraphedAndLinked(): void
    {
        $comments = $this->comments();

        self::assertStringStartsWith(
            '<p>First paragraph.</p><p>See <a href="https://example.org/study"',
            $comments[0]->html,
        );
        self::assertStringContainsString('Thanks &lt;b&gt;Ada&lt;/b&gt;.', $comments[1]->html);
    }

    public function testMarksThePostAuthorsReplies(): void
    {
        self::assertSame(
            [false, true, false],
            array_map(static fn (EntryCommentModel $comment): bool => $comment->byEntryAuthor, $this->comments()),
        );
    }

    public function testCarriesTheCommentDate(): void
    {
        self::assertEquals(new \DateTimeImmutable('2026-10-20 20:44:53.893'), $this->comments()[0]->publishedAt);
    }

    public function testAnAnswerThatIsNoCommentListIsUnavailable(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->source->read(self::entry(), '{"error": "not found"}');
    }

    public function testAPostAnswerWithoutAnIdIsUnavailable(): void
    {
        $this->fetcher->willReturnBody(self::POST_API, '{"slug": "one-post"}');
        $this->expectException(CommentsUnavailableException::class);

        $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
    }

    public function testAnUnreachablePostIsUnavailable(): void
    {
        $this->fetcher->willThrow(self::POST_API, new FeedUnreachableException('gone'));
        $this->expectException(CommentsUnavailableException::class);

        $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
    }

    public function testAThrottledHostIsThrottledAndNotAskedAgain(): void
    {
        $this->fetcher->willThrow(self::POST_API, new FeedThrottledException('slow down', 120));
        try {
            $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
            self::fail('The fetch was not throttled.');
        } catch (CommentsThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
        $this->fetcher->fetchedUrls = [];

        try {
            $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
        } catch (CommentsThrottledException) {
        }

        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAFetchSpendsOneUnitOfTheCommentsBudget(): void
    {
        $this->servePost();
        for ($fetch = 0; $fetch < self::COMMENTS_BUDGET; ++$fetch) {
            $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
        }
        $this->expectException(RateLimitedException::class);

        $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
    }
}
