<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Http\AdminCatalogJson;
use App\Service\Sourcing\Catalog\Model\BundledCatalogSummaryModel;
use App\Service\Sourcing\Catalog\Model\CatalogDocumentCategoryModel;
use App\Service\Sourcing\Catalog\Model\CatalogDocumentFeedModel;
use App\Service\Sourcing\Catalog\Model\ParsedCatalogModel;
use PHPUnit\Framework\TestCase;

final class AdminCatalogJsonTest extends TestCase
{
    public function testTheListingMapsEveryCategoryAndEveryFeed(): void
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, 'The Verge', 'https://example.com/verge.xml');

        self::assertSame(
            ['categories' => [AdminCatalogJson::category($category)], 'feeds' => [AdminCatalogJson::feed($feed)]],
            AdminCatalogJson::listing([$category], [$feed]),
        );
    }

    public function testAnAvailableBundledDocumentReportsItsSize(): void
    {
        $feed = new CatalogDocumentFeedModel('Feed', 'https://example.com/feed.xml', null, null, 'rss');
        $document = new ParsedCatalogModel([
            new CatalogDocumentCategoryModel('a', 'A', 'memory', '#3b82f6', [$feed, $feed]),
            new CatalogDocumentCategoryModel('b', 'B', 'memory', '#3b82f6', [$feed]),
        ]);

        self::assertSame(
            ['available' => true, 'categories' => 2, 'feeds' => 3],
            AdminCatalogJson::bundled(BundledCatalogSummaryModel::of($document)),
        );
    }

    public function testAnUnavailableBundledDocumentReportsZeroes(): void
    {
        self::assertSame(
            ['available' => false, 'categories' => 0, 'feeds' => 0],
            AdminCatalogJson::bundled(BundledCatalogSummaryModel::unavailable()),
        );
    }
}
