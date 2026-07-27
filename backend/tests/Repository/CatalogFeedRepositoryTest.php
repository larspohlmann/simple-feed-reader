<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogFeedRepository;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogFeedRepositoryTest extends DbTestCase
{
    public function testFindEnabledByIdsExcludesDisabledFeedsAndCategoriesAndOrdersByPosition(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        // Category positions are deliberately out of insert order, same as the
        // feed positions within Gadgets below, so a naive "return in whatever
        // order the DB gives them" implementation would fail this test.
        $technology = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $technology->setPosition(1);
        $gadgets = new CatalogCategory('gadgets', 'Gadgets', 'devices', '#f97316');
        $gadgets->setPosition(0);
        $archived = new CatalogCategory('archived', 'Archived', 'archive', '#6b7280');
        $archived->setPosition(2);
        $archived->setEnabled(false);

        $engadget = new CatalogFeed($gadgets, 'Engadget', 'https://www.engadget.com/rss.xml');
        $engadget->setPosition(5);
        $wired = new CatalogFeed($gadgets, 'Wired', 'https://www.wired.com/feed/rss');
        $wired->setPosition(1);
        $deadFeed = new CatalogFeed($gadgets, 'Dead Feed', 'https://example.com/dead.xml');
        $deadFeed->setPosition(2);
        $deadFeed->setEnabled(false);
        $mitReview = new CatalogFeed($technology, 'MIT Technology Review', 'https://www.technologyreview.com/feed/');
        $mitReview->setPosition(0);
        $buriedFeed = new CatalogFeed($archived, 'Buried Feed', 'https://example.com/buried.xml');
        $buriedFeed->setPosition(0);

        foreach ([$technology, $gadgets, $archived, $engadget, $wired, $deadFeed, $mitReview, $buriedFeed] as $row) {
            $em->persist($row);
        }
        $em->flush();
        // See CatalogCategoryRepositoryTest for why: already-managed entities are
        // returned from the identity map without being re-hydrated from the query.
        $em->clear();

        $repository = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $repository);

        $requestedIds = array_map(
            static fn (CatalogFeed $f): int => (int) $f->getId(),
            [$engadget, $wired, $deadFeed, $mitReview, $buriedFeed],
        );
        $requestedIds[] = 999_999; // an id nothing maps to

        $rows = $repository->findEnabledByIds($requestedIds);

        self::assertSame(['Wired', 'Engadget', 'MIT Technology Review'], array_map(
            static fn (CatalogFeed $f): string => $f->getTitle(),
            $rows,
        ));
    }

    public function testFindEnabledByIdsReturnsEmptyForEmptyInput(): void
    {
        $repository = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $repository);

        self::assertSame([], $repository->findEnabledByIds([]));
    }

    public function testFindNeedingFaviconAppliesStaleAndRetryThresholdsAndSkipsDisabledFeeds(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $now = new \DateTimeImmutable('2026-07-01T00:00:00+00:00');
        $staleBefore = $now->modify('-7 days');
        $retryBefore = $now->modify('-1 day');

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        $neverFetched = new CatalogFeed($category, 'Never Fetched Feed', 'https://example.com/never.xml');

        $staleIcon = new CatalogFeed($category, 'Stale Icon Feed', 'https://example.com/stale.xml');
        $staleIcon->storeFavicon(
            'https://example.com/stale-favicon.ico',
            'bytes',
            'image/x-icon',
            $staleBefore->modify('-1 day'),
        );

        $freshIcon = new CatalogFeed($category, 'Fresh Icon Feed', 'https://example.com/fresh.xml');
        $freshIcon->storeFavicon('https://example.com/fresh-favicon.ico', 'bytes', 'image/x-icon', $now);

        $recentlyFailed = new CatalogFeed($category, 'Recently Failed Feed', 'https://example.com/recently-failed.xml');
        $recentlyFailed->recordFaviconFailure($now);

        $longFailed = new CatalogFeed($category, 'Long Failed Feed', 'https://example.com/long-failed.xml');
        $longFailed->recordFaviconFailure($retryBefore->modify('-10 days'));

        $disabled = new CatalogFeed($category, 'Disabled Feed', 'https://example.com/disabled.xml');
        $disabled->setEnabled(false);

        $feeds = [$neverFetched, $staleIcon, $freshIcon, $recentlyFailed, $longFailed, $disabled];
        $em->persist($category);
        foreach ($feeds as $feed) {
            $em->persist($feed);
        }
        $em->flush();
        $em->clear();

        $repository = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $repository);

        $rows = $repository->findNeedingFavicon($staleBefore, $retryBefore, null);

        self::assertSame(['Never Fetched Feed', 'Stale Icon Feed', 'Long Failed Feed'], array_map(
            static fn (CatalogFeed $f): string => $f->getTitle(),
            $rows,
        ));
    }

    public function testFindNeedingFaviconRespectsLimit(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $now = new \DateTimeImmutable('2026-07-01T00:00:00+00:00');
        $staleBefore = $now->modify('-7 days');
        $retryBefore = $now->modify('-1 day');

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $first = new CatalogFeed($category, 'First Feed', 'https://example.com/first.xml');
        $second = new CatalogFeed($category, 'Second Feed', 'https://example.com/second.xml');
        $third = new CatalogFeed($category, 'Third Feed', 'https://example.com/third.xml');

        $em->persist($category);
        foreach ([$first, $second, $third] as $feed) {
            $em->persist($feed);
        }
        $em->flush();
        $em->clear();

        $repository = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $repository);

        $rows = $repository->findNeedingFavicon($staleBefore, $retryBefore, 2);

        self::assertCount(2, $rows);
    }
}
