<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\Comments\CommentsCache;
use App\Service\Comments\CommentsLoader;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Exception\NoCommentsFeedException;
use App\Service\Comments\Model\CommentsResultModel;
use App\Service\Comments\Model\CommentsStatus;
use App\Tests\Support\FakeCommentsSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class CommentsLoaderTest extends TestCase
{
    private const int SETTLED_LIFETIME = 7200;
    private const int GATHERING_LIFETIME = 60;

    private MockClock $clock;
    private FakeCommentsSource $source;
    private User $user;
    private Entry $entry;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-16 12:00:00');
        $this->source = new FakeCommentsSource('answer');
        $this->user = new User('reader@example.com', $this->clock->now());
        $this->entry = new Entry(
            new Feed('https://blog.example.org/feed/'),
            'https://blog.example.org/?p=7',
            null,
            'Hello',
            $this->clock->now(),
            $this->clock->now(),
        );
    }

    /** @param list<FakeCommentsSource> $sources */
    private function loader(array $sources = []): CommentsLoader
    {
        $cache = new CommentsCache(
            new ArrayAdapter(clock: $this->clock),
            self::SETTLED_LIFETIME,
            self::GATHERING_LIFETIME,
        );

        return new CommentsLoader([] === $sources ? [$this->source] : $sources, $cache, $this->clock);
    }

    private static function firstHtml(CommentsResultModel $result): string
    {
        self::assertNotSame([], $result->comments);

        return $result->comments[0]->html;
    }

    public function testAFreshFetchIsReadAndStamped(): void
    {
        $result = $this->loader()->load($this->user, $this->entry);

        self::assertSame(CommentsStatus::Ok, $result->status);
        self::assertSame('answer-1', self::firstHtml($result));
        self::assertEquals($this->clock->now(), $result->fetchedAt);
        self::assertCount(1, $this->source->fetches);
    }

    public function testASecondLoadIsAnsweredFromTheCacheWithTheFirstFetchTime(): void
    {
        $loader = $this->loader();
        $firstFetch = $this->clock->now();

        $loader->load($this->user, $this->entry);
        $this->clock->sleep(600);
        $result = $loader->load($this->user, $this->entry);

        self::assertCount(1, $this->source->fetches);
        self::assertSame('answer-1', self::firstHtml($result));
        self::assertEquals($firstFetch, $result->fetchedAt);
    }

    public function testRefreshedAsksTheSourceAgain(): void
    {
        $loader = $this->loader();

        $loader->load($this->user, $this->entry);
        $this->clock->sleep(600);
        $result = $loader->refreshed($this->user, $this->entry);

        self::assertCount(2, $this->source->fetches);
        self::assertSame('answer-2', self::firstHtml($result));
        self::assertEquals($this->clock->now(), $result->fetchedAt);
    }

    public function testARefreshedAnswerIsWhatTheNextLoadReads(): void
    {
        $loader = $this->loader();

        $loader->load($this->user, $this->entry);
        $loader->refreshed($this->user, $this->entry);
        $result = $loader->load($this->user, $this->entry);

        self::assertCount(2, $this->source->fetches);
        self::assertSame('answer-2', self::firstHtml($result));
    }

    public function testAnAnswerStillGatheringExpiresAfterAMinute(): void
    {
        $loader = $this->loader();
        $this->source->stillGathering();

        $loader->load($this->user, $this->entry);
        $this->clock->sleep(self::GATHERING_LIFETIME - 1);
        $loader->load($this->user, $this->entry);
        self::assertCount(1, $this->source->fetches);

        $this->clock->sleep(2);
        $loader->load($this->user, $this->entry);
        self::assertCount(2, $this->source->fetches);
    }

    public function testASettledAnswerLastsTwoHours(): void
    {
        $loader = $this->loader();

        $loader->load($this->user, $this->entry);
        $this->clock->sleep(self::SETTLED_LIFETIME - 1);
        $loader->load($this->user, $this->entry);
        self::assertCount(1, $this->source->fetches);

        $this->clock->sleep(2);
        $loader->load($this->user, $this->entry);
        self::assertCount(2, $this->source->fetches);
    }

    public function testAThrottledSourceReportsWhenToRetry(): void
    {
        $this->source->failWith(new CommentsThrottledException('slow', 30));

        $result = $this->loader()->load($this->user, $this->entry);

        self::assertSame(CommentsStatus::Throttled, $result->status);
        self::assertSame(30, $result->retryAfter);
    }

    public function testAnUnavailableSourceReportsFailedAndCachesNothing(): void
    {
        $loader = $this->loader();
        $this->source->failWith(new CommentsUnavailableException('down'));

        $failed = $loader->load($this->user, $this->entry);
        $this->source->recover();
        $recovered = $loader->load($this->user, $this->entry);

        self::assertSame(CommentsStatus::Failed, $failed->status);
        self::assertSame(CommentsStatus::Ok, $recovered->status);
        self::assertCount(2, $this->source->fetches);
    }

    public function testAnAnswerThatCannotBeReadIsFailedAndCachesNothing(): void
    {
        $loader = $this->loader();
        $this->source->cannotRead('answer-1');

        $failed = $loader->load($this->user, $this->entry);
        $recovered = $loader->load($this->user, $this->entry);

        self::assertSame(CommentsStatus::Failed, $failed->status);
        self::assertSame(CommentsStatus::Ok, $recovered->status);
        self::assertSame('answer-2', self::firstHtml($recovered));
        self::assertCount(2, $this->source->fetches);
    }

    public function testACachedAnswerThatNoLongerReadsIsFetchedAfresh(): void
    {
        $loader = $this->loader();
        $loader->load($this->user, $this->entry);
        $this->source->cannotRead('answer-1');

        $refetched = $loader->load($this->user, $this->entry);
        $cached = $loader->load($this->user, $this->entry);

        self::assertSame('answer-2', self::firstHtml($refetched));
        self::assertEquals($this->clock->now(), $refetched->fetchedAt);
        self::assertSame('answer-2', self::firstHtml($cached));
        self::assertCount(2, $this->source->fetches);
    }

    public function testACachedAnswerThatNoLongerReadsIsFetchedAfreshOnlyOnce(): void
    {
        $loader = $this->loader();
        $loader->load($this->user, $this->entry);
        $this->source->cannotRead('answer-1', 'answer-2');

        $result = $loader->load($this->user, $this->entry);

        self::assertSame(CommentsStatus::Failed, $result->status);
        self::assertCount(2, $this->source->fetches);
    }

    public function testALoadAsksTheAudienceOnce(): void
    {
        $this->loader()->load($this->user, $this->entry);

        self::assertSame(1, $this->source->audienceAsks);
    }

    public function testAnEntryNoSourceServesIsRefused(): void
    {
        $this->source->servesEntries = false;

        $this->expectException(NoCommentsFeedException::class);

        $this->loader()->load($this->user, $this->entry);
    }

    public function testTheFirstSourceThatServesTheEntryAnswers(): void
    {
        $declining = new FakeCommentsSource('declined');
        $declining->servesEntries = false;
        $serving = new FakeCommentsSource('served');

        $result = $this->loader([$declining, $serving])->load($this->user, $this->entry);

        self::assertSame([], $declining->fetches);
        self::assertCount(1, $serving->fetches);
        self::assertSame('served-1', self::firstHtml($result));
    }
}
