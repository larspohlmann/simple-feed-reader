<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\Model\BundledCatalogSummaryModel;
use App\Service\Catalog\Model\CatalogImportResultModel;

/** The admin view of the catalog: every row, enabled or not. */
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function importResult(CatalogImportResultModel $result): array
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

    /** @return array{available: bool, categories: int, feeds: int} */
    public static function bundled(BundledCatalogSummaryModel $summary): array
    {
        return [
            'available' => $summary->available,
            'categories' => $summary->categories,
            'feeds' => $summary->feeds,
        ];
    }
}
