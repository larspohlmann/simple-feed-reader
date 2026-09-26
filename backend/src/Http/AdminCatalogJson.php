<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\BundledCatalogSummary;
use App\Service\Catalog\CatalogImportResult;
use App\Service\Catalog\CatalogWarmReport;

/**
 * The admin view of the catalog: every row, enabled or not, plus the favicon
 * bookkeeping an admin needs to see to know whether an icon is missing because
 * it has not been warmed or because it keeps failing.
 */
final class AdminCatalogJson
{
    /**
     * @return array<string, mixed>
     */
    public static function category(CatalogCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'key' => $category->getKey(),
            'name' => $category->getName(),
            'icon' => $category->getIcon(),
            'color' => $category->getColor(),
            'position' => $category->getPosition(),
            'enabled' => $category->isEnabled(),
            'locked' => $category->isLocked(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function feed(CatalogFeed $feed): array
    {
        return [
            'id' => $feed->getId(),
            'categoryId' => $feed->getCategory()->getId(),
            'title' => $feed->getTitle(),
            'url' => $feed->getUrl(),
            'siteUrl' => $feed->getSiteUrl(),
            'description' => $feed->getDescription(),
            'sourceFormat' => $feed->getSourceFormat(),
            'position' => $feed->getPosition(),
            'enabled' => $feed->isEnabled(),
            'locked' => $feed->isLocked(),
            'faviconFetchedAt' => $feed->getFaviconFetchedAt()?->format(\DATE_ATOM),
            'faviconFailedAt' => $feed->getFaviconFailedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function importResult(CatalogImportResult $result): array
    {
        return [
            'categoriesCreated' => $result->categoriesCreated,
            'categoriesUpdated' => $result->categoriesUpdated,
            'categoriesRemoved' => $result->categoriesRemoved,
            'feedsCreated' => $result->feedsCreated,
            'feedsUpdated' => $result->feedsUpdated,
            'feedsRemoved' => $result->feedsRemoved,
            'lockedSkipped' => $result->lockedSkipped,
        ];
    }

    /**
     * @param list<CatalogCategory> $categories
     * @param list<CatalogFeed>     $feeds
     *
     * @return array{categories: list<array<string, mixed>>, feeds: list<array<string, mixed>>}
     */
    public static function listing(array $categories, array $feeds): array
    {
        return [
            'categories' => array_map(self::category(...), $categories),
            'feeds' => array_map(self::feed(...), $feeds),
        ];
    }

    /** @return array{warmed: int, failed: int, remaining: int} */
    public static function warmReport(CatalogWarmReport $report): array
    {
        return [
            'warmed' => $report->warmed,
            'failed' => $report->failed,
            'remaining' => $report->remaining,
        ];
    }

    /** @return array{available: bool, categories: int, feeds: int} */
    public static function bundled(BundledCatalogSummary $summary): array
    {
        return [
            'available' => $summary->available,
            'categories' => $summary->categories,
            'feeds' => $summary->feeds,
        ];
    }
}
