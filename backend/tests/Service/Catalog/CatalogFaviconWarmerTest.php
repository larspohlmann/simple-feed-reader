<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\CatalogFaviconWarmer;
use App\Service\Catalog\FetchedFavicon;
use App\Service\Fetch\FaviconResolverInterface;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Clock\ClockInterface;

final class CatalogFaviconWarmerTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function feeds(): CatalogFeedRepository
    {
        $feeds = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $feeds);

        return $feeds;
    }

    private function clock(): ClockInterface
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock;
    }

    private function warmer(
        FaviconResolverInterface $resolver,
        CatalogFaviconFetcherInterface $fetcher,
    ): CatalogFaviconWarmer {
        return new CatalogFaviconWarmer($this->feeds(), $resolver, $fetcher, $this->em(), $this->clock());
    }

    private function persistFeed(string $title): CatalogFeed
    {
        $category = new CatalogCategory('technology' . $title, 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, $title, 'https://' . strtolower($title) . '.example.com/rss.xml');
        $feed->setSiteUrl('https://example.com');
        $this->em()->persist($category);
        $this->em()->persist($feed);
        $this->em()->flush();

        return $feed;
    }

    /**
     * @param array<int, string|null> $urlsByKey
     *
     * @return FaviconResolverInterface&Stub
     */
    private function resolverReturning(array $urlsByKey): FaviconResolverInterface
    {
        $resolver = $this->createStub(FaviconResolverInterface::class);
        $resolver->method('resolveAll')->willReturnCallback(
            static function (array $bases) use ($urlsByKey): array {
                $resolved = [];
                foreach (array_keys($bases) as $offset => $key) {
                    $resolved[$key] = array_values($urlsByKey)[$offset] ?? null;
                }

                return $resolved;
            },
        );

        return $resolver;
    }

    public function testResolvesDownloadsAndStoresAnIcon(): void
    {
        $feed = $this->persistFeed('Verge');
        $resolver = $this->resolverReturning([0 => 'https://example.com/favicon.ico']);

        $fetcher = $this->createMock(CatalogFaviconFetcherInterface::class);
        $fetcher->expects(self::once())
            ->method('download')
            ->with('https://example.com/favicon.ico')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $report = $this->warmer($resolver, $fetcher)->warm(120);

        self::assertSame(1, $report->warmed);
        self::assertSame(0, $report->failed);
        self::assertSame(0, $report->remaining);

        $this->em()->clear();
        $reloaded = $this->em()->find(CatalogFeed::class, $feed->getId());
        self::assertNotNull($reloaded);
        self::assertSame('PNGBYTES', $reloaded->getFaviconBytes());
    }

    public function testUnresolvableIconIsARecordedFailureNeverDownloaded(): void
    {
        $feed = $this->persistFeed('Dead');
        $resolver = $this->resolverReturning([0 => null]);

        $fetcher = $this->createMock(CatalogFaviconFetcherInterface::class);
        $fetcher->expects(self::never())->method('download');

        $report = $this->warmer($resolver, $fetcher)->warm(120);

        self::assertSame(0, $report->warmed);
        self::assertSame(1, $report->failed);

        $this->em()->clear();
        $reloaded = $this->em()->find(CatalogFeed::class, $feed->getId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getFaviconFailedAt());
    }

    public function testRefreshReFetchesOneRow(): void
    {
        $feed = $this->persistFeed('One');
        $resolver = $this->resolverReturning([0 => 'https://example.com/favicon.ico']);

        $fetcher = $this->createStub(CatalogFaviconFetcherInterface::class);
        $fetcher->method('download')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'ICOBYTES', 'image/x-icon'));

        $this->warmer($resolver, $fetcher)->refresh($feed);

        $this->em()->clear();
        $reloaded = $this->em()->find(CatalogFeed::class, $feed->getId());
        self::assertNotNull($reloaded);
        self::assertSame('ICOBYTES', $reloaded->getFaviconBytes());
    }

    public function testRefreshRecordsAFailureWhenTheSiteResolvesToNull(): void
    {
        $feed = $this->persistFeed('Two');
        $resolver = $this->resolverReturning([0 => null]);

        $fetcher = $this->createMock(CatalogFaviconFetcherInterface::class);
        $fetcher->expects(self::never())->method('download');

        $this->warmer($resolver, $fetcher)->refresh($feed);

        $this->em()->clear();
        $reloaded = $this->em()->find(CatalogFeed::class, $feed->getId());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getFaviconFailedAt());
    }

    public function testMarkAllForReWarmingMakesFreshRowsDueAgain(): void
    {
        $feed = $this->persistFeed('Fresh');
        $resolver = $this->resolverReturning([0 => 'https://example.com/favicon.ico']);
        $fetcher = $this->createStub(CatalogFaviconFetcherInterface::class);
        $fetcher->method('download')
            ->willReturn(new FetchedFavicon('https://example.com/favicon.ico', 'PNGBYTES', 'image/png'));

        $warmer = $this->warmer($resolver, $fetcher);
        $warmer->warm(120);

        // Warmed rows drop out of the due set.
        self::assertNotContainsFeed($feed, $this->due());

        $warmer->markAllForReWarming();

        // ...until force marks them due again.
        self::assertContainsFeed($feed, $this->due());
    }

    /**
     * @return list<CatalogFeed>
     */
    private function due(): array
    {
        $now = $this->clock()->now();

        return $this->feeds()->findNeedingFavicon(
            $now->sub(new \DateInterval('P90D')),
            $now->sub(new \DateInterval('P14D')),
            null,
        );
    }

    /**
     * @param list<CatalogFeed> $feeds
     */
    private static function assertContainsFeed(CatalogFeed $needle, array $feeds): void
    {
        self::assertContains($needle->getId(), array_map(static fn (CatalogFeed $f): ?int => $f->getId(), $feeds));
    }

    /**
     * @param list<CatalogFeed> $feeds
     */
    private static function assertNotContainsFeed(CatalogFeed $needle, array $feeds): void
    {
        self::assertNotContains($needle->getId(), array_map(static fn (CatalogFeed $f): ?int => $f->getId(), $feeds));
    }
}
