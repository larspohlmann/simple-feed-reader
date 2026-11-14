<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/** For a KernelTestCase whose `$source` is a comments source wired onto a stub fetcher. */
trait BuildsCommentsSources
{
    private StubFeedFetcher $fetcher;
    private MockClock $clock;
    private HostThrottle $throttle;
    private User $user;

    /**
     * @template TSource of object
     * @param class-string<TSource> $sourceClass
     * @return TSource
     */
    private function bootCommentsSource(string $sourceClass, string $now): object
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        $this->fetcher = new StubFeedFetcher();
        $this->clock = new MockClock($now);
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->user = new User('reader@example.com', $this->clock->now());
        $container = self::getContainer();
        $container->set(FeedFetcherInterface::class, $this->fetcher);
        $container->set(HostThrottle::class, $this->throttle);
        $source = $container->get($sourceClass);
        self::assertInstanceOf($sourceClass, $source);

        return $source;
    }

    private function requestFor(Entry $entry): CommentsRequest
    {
        return new CommentsRequest($this->source, $this->user, $entry);
    }
}
