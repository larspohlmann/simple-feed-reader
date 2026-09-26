<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Http\AdminCatalogJson;
use App\Service\Catalog\BundledCatalogSummary;
use App\Service\Catalog\CatalogDocumentCategory;
use App\Service\Catalog\CatalogDocumentFeed;
use App\Service\Catalog\CatalogWarmReport;
use App\Service\Catalog\ParsedCatalog;
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

    public function testTheWarmReportMapsEveryCount(): void
    {
        self::assertSame(
            ['warmed' => 3, 'failed' => 1, 'remaining' => 7],
            AdminCatalogJson::warmReport(new CatalogWarmReport(3, 1, 7)),
        );
    }

    public function testAnAvailableBundledDocumentReportsItsSize(): void
    {
        $feed = new CatalogDocumentFeed('Feed', 'https://example.com/feed.xml', null, null, 'rss');
        $document = new ParsedCatalog([
            new CatalogDocumentCategory('a', 'A', 'memory', '#3b82f6', [$feed, $feed]),
            new CatalogDocumentCategory('b', 'B', 'memory', '#3b82f6', [$feed]),
        ]);

        self::assertSame(
            ['available' => true, 'categories' => 2, 'feeds' => 3],
            AdminCatalogJson::bundled(BundledCatalogSummary::of($document)),
        );
    }

    public function testAnUnavailableBundledDocumentReportsZeroes(): void
    {
        self::assertSame(
            ['available' => false, 'categories' => 0, 'feeds' => 0],
            AdminCatalogJson::bundled(BundledCatalogSummary::unavailable()),
        );
    }
}
