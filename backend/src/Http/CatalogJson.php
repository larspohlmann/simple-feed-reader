<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;

final class CatalogJson
{
    /**
     * @param list<CatalogCategory>  $categories
     * @param array<string, true>    $subscribedUrls
     *
     * @return array{categories: list<array<string, mixed>>}
     */
    public static function many(array $categories, array $subscribedUrls): array
    {
        return [
            'categories' => array_map(
                static fn (CatalogCategory $category): array => self::category($category, $subscribedUrls),
                $categories,
            ),
        ];
    }

    /**
     * @param array<string, true> $subscribedUrls
     *
     * @return array<string, mixed>
     */
    private static function category(CatalogCategory $category, array $subscribedUrls): array
    {
        return [
            'id' => $category->getId(),
            'key' => $category->getKey(),
            'name' => $category->getName(),
            'icon' => $category->getIcon(),
            'color' => $category->getColor(),
            'feeds' => array_map(
                static fn (CatalogFeed $feed): array => self::feed($feed, $subscribedUrls),
                $category->getEnabledFeeds(),
            ),
        ];
    }

    /**
     * @param array<string, true> $subscribedUrls
     *
     * @return array<string, mixed>
     */
    private static function feed(CatalogFeed $feed, array $subscribedUrls): array
    {
        return [
            'id' => $feed->getId(),
            'title' => $feed->getTitle(),
            'description' => $feed->getDescription(),
            'siteUrl' => $feed->getSiteUrl(),
            'subscribed' => isset($subscribedUrls[$feed->getUrl()]),
        ];
    }
}
