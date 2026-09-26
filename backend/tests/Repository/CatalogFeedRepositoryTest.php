<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogFaviconDueCriteria;
use App\Repository\CatalogFeedRepository;
use App\Tests\DbTestCase;

final class CatalogFeedRepositoryTest extends DbTestCase
{
    private const string NOW = '2026-07-01T00:00:00+00:00';

    public function testFindEnabledByIdsExcludesDisabledFeedsAndCategoriesAndOrdersByPosition(): void
    {
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
            $this->em->persist($row);
        }
        $this->em->flush();
        // See CatalogCategoryRepositoryTest for why: already-managed entities are
        // returned from the identity map without being re-hydrated from the query.
        $this->em->clear();

        $requestedIds = array_map(
            static fn (CatalogFeed $f): int => $f->requireId(),
            [$engadget, $wired, $deadFeed, $mitReview, $buriedFeed],
        );
        $requestedIds[] = 999_999; // an id nothing maps to

        $rows = $this->catalogFeeds()->findEnabledByIds($requestedIds);

        self::assertSame(['Wired', 'Engadget', 'MIT Technology Review'], array_map(
            static fn (CatalogFeed $f): string => $f->getTitle(),
            $rows,
        ));
    }

    public function testFindEnabledByIdsReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], $this->catalogFeeds()->findEnabledByIds([]));
    }

    public function testFindNeedingFaviconAppliesStaleAndRetryThresholdsAndSkipsDisabledFeeds(): void
    {
        $this->persistFaviconQueue();

        $rows = $this->catalogFeeds()->findNeedingFavicon($this->faviconCriteria(), null);

        self::assertSame(['Never Fetched Feed', 'Stale Icon Feed', 'Long Failed Feed'], array_map(
            static fn (CatalogFeed $f): string => $f->getTitle(),
            $rows,
        ));
    }

    public function testCountNeedingFaviconCountsTheRowsFindNeedingFaviconReturns(): void
    {
        $this->persistFaviconQueue();

        self::assertSame(3, $this->catalogFeeds()->countNeedingFavicon($this->faviconCriteria()));
    }

    public function testFindNeedingFaviconRespectsLimit(): void
    {
        $this->persistFaviconQueue();

        self::assertCount(2, $this->catalogFeeds()->findNeedingFavicon($this->faviconCriteria(), 2));
    }

    public function testFindAllOrderedSortsByPositionThenTitleAndKeepsDisabledFeeds(): void
    {
        $category = new CatalogCategory('ordering', 'Ordering', 'sort', '#6b7280');
        $bravo = new CatalogFeed($category, 'Bravo', 'https://example.com/bravo.xml');
        $bravo->setPosition(1);
        $alpha = new CatalogFeed($category, 'Alpha', 'https://example.com/alpha.xml');
        $alpha->setPosition(1);
        $zulu = new CatalogFeed($category, 'Zulu', 'https://example.com/zulu.xml');
        $zulu->setPosition(0);
        $zulu->setEnabled(false);
        $this->em->persist($category);
        foreach ([$bravo, $alpha, $zulu] as $feed) {
            $this->em->persist($feed);
        }
        $this->em->flush();

        $mine = array_filter(
            $this->catalogFeeds()->findAllOrdered(),
            static fn (CatalogFeed $feed): bool => $feed->getCategory() === $category,
        );

        self::assertSame(
            ['Zulu', 'Alpha', 'Bravo'],
            array_values(array_map(static fn (CatalogFeed $feed): string => $feed->getTitle(), $mine)),
        );
    }

    private function catalogFeeds(): CatalogFeedRepository
    {
        $repository = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $repository);

        return $repository;
    }

    private function faviconCriteria(): CatalogFaviconDueCriteria
    {
        $now = new \DateTimeImmutable(self::NOW);

        return new CatalogFaviconDueCriteria($now->modify('-7 days'), $now->modify('-1 day'));
    }

    /**
     * Six feeds, three of them due: never fetched, stale, and failed before the retry window.
     */
    private function persistFaviconQueue(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $criteria = $this->faviconCriteria();

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        $neverFetched = new CatalogFeed($category, 'Never Fetched Feed', 'https://example.com/never.xml');

        $staleIcon = new CatalogFeed($category, 'Stale Icon Feed', 'https://example.com/stale.xml');
        $staleIcon->storeFavicon(
            'https://example.com/stale-favicon.ico',
            'bytes',
            'image/x-icon',
            $criteria->staleBefore->modify('-1 day'),
        );

        $freshIcon = new CatalogFeed($category, 'Fresh Icon Feed', 'https://example.com/fresh.xml');
        $freshIcon->storeFavicon('https://example.com/fresh-favicon.ico', 'bytes', 'image/x-icon', $now);

        $recentlyFailed = new CatalogFeed($category, 'Recently Failed Feed', 'https://example.com/recently-failed.xml');
        $recentlyFailed->recordFaviconFailure($now);

        $longFailed = new CatalogFeed($category, 'Long Failed Feed', 'https://example.com/long-failed.xml');
        $longFailed->recordFaviconFailure($criteria->retryBefore->modify('-10 days'));

        $disabled = new CatalogFeed($category, 'Disabled Feed', 'https://example.com/disabled.xml');
        $disabled->setEnabled(false);

        $this->em->persist($category);
        foreach ([$neverFetched, $staleIcon, $freshIcon, $recentlyFailed, $longFailed, $disabled] as $feed) {
            $this->em->persist($feed);
        }
        $this->em->flush();
        $this->em->clear();
    }
}
