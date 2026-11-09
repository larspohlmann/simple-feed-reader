<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogFeedRepository;
use App\Tests\DbTestCase;

final class CatalogFeedRepositoryTest extends DbTestCase
{
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
            $this->entityManager->persist($row);
        }
        $this->entityManager->flush();
        // See CatalogCategoryRepositoryTest for why: already-managed entities are
        // returned from the identity map without being re-hydrated from the query.
        $this->entityManager->clear();

        $requestedIds = array_map(
            static fn (CatalogFeed $feed): int => $feed->requireId(),
            [$engadget, $wired, $deadFeed, $mitReview, $buriedFeed],
        );
        $requestedIds[] = 999_999; // an id nothing maps to

        $rows = $this->catalogFeeds()->findEnabledByIds($requestedIds);

        self::assertSame(['Wired', 'Engadget', 'MIT Technology Review'], array_map(
            static fn (CatalogFeed $feed): string => $feed->getTitle(),
            $rows,
        ));
    }

    public function testFindEnabledByIdsReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], $this->catalogFeeds()->findEnabledByIds([]));
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
        $this->entityManager->persist($category);
        foreach ([$bravo, $alpha, $zulu] as $feed) {
            $this->entityManager->persist($feed);
        }
        $this->entityManager->flush();

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
}
