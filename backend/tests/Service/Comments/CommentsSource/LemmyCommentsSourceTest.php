<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\CommentsSource;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\Feed;
use App\Entity\User;
use App\Enum\CommentsLoad;
use App\Service\Comments\CommentsSource\LemmyCommentsSource;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\RateLimit\Exception\RateLimitedException;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LemmyCommentsSourceTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../Fixtures/lemmy/comment-list.json';
    private const string POST = 'https://lemmy.world/post/54385737';
    private const string COMMENTS_API =
        'https://lemmy.world/api/v3/comment/list?post_id=54385737&sort=Hot&type_=All&max_depth=8&limit=50';
    private const string POST_AUTHOR = 'https://lemmy.world/u/inclementimmigrant';

    /** Must match framework.rate_limiter.comments.limit in rate_limiter.yaml. */
    private const int COMMENTS_BUDGET = 30;

    private StubFeedFetcher $fetcher;
    private MockClock $clock;
    private User $user;
    private LemmyCommentsSource $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        $this->fetcher = new StubFeedFetcher();
        $this->clock = new MockClock('2026-11-14 12:00:00');
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->user = new User('reader@example.com', $this->clock->now());
        $container = self::getContainer();
        $container->set(FeedFetcherInterface::class, $this->fetcher);
        $container->set(HostThrottle::class, $throttle);
        $source = $container->get(LemmyCommentsSource::class);
        self::assertInstanceOf(LemmyCommentsSource::class, $source);
        $this->source = $source;
    }

    private static function entry(?string $url = null, ?Discussion $discussion = null): Entry
    {
        $now = new \DateTimeImmutable();
        $entry = new Entry(new Feed('https://lemmy.world/feeds/c/news.xml'), self::POST, $url, 'A post', $now, $now);
        $entry->setAuthor(new EntryAuthor('inclementimmigrant', self::POST_AUTHOR));
        $entry->setDiscussion($discussion ?? Discussion::platformThread(self::POST));

        return $entry;
    }

    /** @return list<EntryCommentModel> */
    private function comments(): array
    {
        $this->fetcher->willReturnBody(self::COMMENTS_API, (string) file_get_contents(self::FIXTURE));
        $entry = self::entry();

        return $this->source->read($this->requestFor($entry), $this->fetch($entry)->body)->comments;
    }

    private function fetch(Entry $entry): CommentsAnswerModel
    {
        return $this->source->fetch($this->requestFor($entry));
    }

    /** @return list<EntryCommentModel> */
    private function read(string $answer): array
    {
        return $this->source->read($this->requestFor(self::entry()), $answer)->comments;
    }

    public function testServesALemmyDiscussionThread(): void
    {
        self::assertTrue($this->source->serves(self::entry()));
    }

    public function testServesNoRedditThread(): void
    {
        $reddit = Discussion::withCommentsFeed(self::POST, self::POST . '.rss', CommentsLoad::Auto);

        self::assertFalse($this->source->serves(self::entry(discussion: $reddit)));
    }

    public function testServesNoEntryWithAUrl(): void
    {
        self::assertFalse($this->source->serves(self::entry('https://example.org/article')));
    }

    public function testServesNoThreadWhoseAddressIsNoPost(): void
    {
        $other = Discussion::platformThread('https://lemmy.world/c/news');

        self::assertFalse($this->source->serves(self::entry(discussion: $other)));
    }

    /** @return iterable<string, array{string}> */
    public static function addressesThatAreNoCleanHost(): iterable
    {
        yield 'userinfo' => ['https://u@h.example/post/1'];
        yield 'port' => ['https://h.example:8080/post/1'];
        yield 'query on the host' => ['https://h.example?x/post/1'];
        yield 'fragment on the host' => ['https://h.example#x/post/1'];
    }

    #[DataProvider('addressesThatAreNoCleanHost')]
    public function testServesNoPostWhoseHostIsNoCleanHost(string $address): void
    {
        self::assertFalse($this->source->serves(self::entry(discussion: Discussion::platformThread($address))));
    }

    public function testEveryReaderOfAPostSharesItsAnswer(): void
    {
        $other = new User('other@example.com', $this->clock->now());

        self::assertSame(self::POST, $this->source->audienceOf($this->user, self::entry())->scope);
        self::assertSame(self::POST, $this->source->audienceOf($other, self::entry())->scope);
    }

    public function testAsksTheInstanceApi(): void
    {
        $this->fetcher->willReturnBody(self::COMMENTS_API, '{"comments": []}');

        $this->fetch(self::entry());

        self::assertSame([self::COMMENTS_API], $this->fetcher->fetchedUrls);
    }

    public function testThreadsByPathDepthFirst(): void
    {
        self::assertSame(
            [
                ['https://piefed.ca/comment/7077799', 0],
                ['https://lemmy.world/comment/26859426', 0],
                ['https://sh.itjust.works/comment/28579580', 1],
                ['https://thelemmy.club/comment/30356546', 0],
                ['https://sh.itjust.works/comment/28577929', 1],
                ['https://lemmy.world/comment/26858462', 0],
                ['https://lemmy.ml/comment/29014742', 1],
            ],
            array_map(
                static fn (EntryCommentModel $comment): array => [$comment->url, $comment->depth],
                $this->comments(),
            ),
        );
    }

    public function testACraftedAnswerWithRepeatedAndSelfParentedIdsEmitsEachCommentOnce(): void
    {
        $answer = (string) json_encode(['comments' => [
            self::node(5, '0.5'),
            self::node(5, '0.5.5'),
            self::node(6, '0.6.6'),
            self::node(7, '0.5.7'),
        ]]);

        self::assertSame(
            [['https://lemmy.world/comment/5', 0], ['https://lemmy.world/comment/7', 1]],
            self::urlsAndDepths($this->read($answer)),
        );
    }

    public function testARemovedCommentDropsButItsReplyStays(): void
    {
        $urls = array_map(static fn (EntryCommentModel $comment): ?string => $comment->url, $this->comments());

        self::assertNotContains('https://lemmy.world/comment/26858771', $urls);
        self::assertContains('https://lemmy.world/comment/26859426', $urls);
    }

    public function testACommenterIsNamedLinkedAndPictured(): void
    {
        $comments = $this->comments();

        self::assertSame('ZeroCool', $comments[0]->author->name);
        self::assertSame('@ZeroCool@piefed.ca', $comments[0]->author->handle);
        self::assertSame('https://piefed.ca/u/ZeroCool', $comments[0]->author->profileUrl);
        self::assertSame('https://media.piefed.ca/users/1N/ki/1Nkix9Rh7H5O55T.jpg', $comments[0]->author->avatarUrl);
        self::assertSame('Pika', $comments[4]->author->name);
        self::assertEquals(new \DateTimeImmutable('2026-11-14 08:18:08.294711'), $comments[0]->publishedAt);
    }

    public function testACommenterWithoutADisplayNameIsNamedByAccount(): void
    {
        $author = $this->comments()[1]->author;

        self::assertSame('lemonbun', $author->name);
        self::assertSame('@lemonbun@lemmy.world', $author->handle);
        self::assertNull($author->avatarUrl);
    }

    public function testMarksThePostAuthorsReplies(): void
    {
        self::assertSame(
            [false, false, false, false, false, true, false],
            array_map(static fn (EntryCommentModel $comment): bool => $comment->byEntryAuthor, $this->comments()),
        );
    }

    public function testABodyIsRenderedMarkdown(): void
    {
        $answer = '{"comments": [{"comment": {"id": 1, "path": "0.1", "ap_id": "https://lemmy.world/comment/1",'
            . ' "published": "2026-11-14T08:18:08Z", "content": "> quoted\n\n**bold** <script>x</script>"},'
            . ' "creator": {"name": "a", "actor_id": "https://lemmy.world/u/a"}}]}';

        $html = $this->read($answer)[0]->html;

        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAnAnswerThatIsNoCommentListIsUnavailable(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->read('{"error": "couldnt_find_post"}');
    }

    public function testAnUnreachableInstanceIsUnavailable(): void
    {
        $this->fetcher->willThrow(self::COMMENTS_API, new FeedUnreachableException('gone'));
        $this->expectException(CommentsUnavailableException::class);

        $this->fetch(self::entry());
    }

    public function testAThrottledHostIsThrottledAndNotAskedAgain(): void
    {
        $this->fetcher->willThrow(self::COMMENTS_API, new FeedThrottledException('slow down', 120));
        try {
            $this->fetch(self::entry());
            self::fail('The fetch was not throttled.');
        } catch (CommentsThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
        $this->fetcher->fetchedUrls = [];

        try {
            $this->fetch(self::entry());
        } catch (CommentsThrottledException) {
        }

        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAFetchSpendsOneUnitOfTheCommentsBudget(): void
    {
        $this->fetcher->willReturnBody(self::COMMENTS_API, '{"comments": []}');
        for ($fetch = 0; $fetch < self::COMMENTS_BUDGET; ++$fetch) {
            $this->fetch(self::entry());
        }
        $this->expectException(RateLimitedException::class);

        $this->fetch(self::entry());
    }

    public function testAnEntryThatIsNoLemmyPostIsUnavailable(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->fetch(self::entry('https://example.org/article'));
    }

    /** @return array<string, mixed> */
    private static function node(int $id, string $path): array
    {
        return [
            'comment' => ['id' => $id, 'path' => $path, 'ap_id' => 'https://lemmy.world/comment/' . $id,
                'content' => 'Text ' . $id],
            'creator' => ['name' => 'a', 'actor_id' => 'https://lemmy.world/u/a'],
        ];
    }

    /**
     * @param list<EntryCommentModel> $comments
     *
     * @return list<array{string|null, int}>
     */
    private static function urlsAndDepths(array $comments): array
    {
        return array_map(
            static fn (EntryCommentModel $comment): array => [$comment->url, $comment->depth],
            $comments,
        );
    }

    private function requestFor(Entry $entry): CommentsRequest
    {
        return new CommentsRequest($this->source, $this->user, $entry);
    }
}
