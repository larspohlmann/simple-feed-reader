<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogFaviconSource;
use App\Service\Catalog\MonogramFavicon;
use PHPUnit\Framework\TestCase;

final class CatalogFaviconSourceTest extends TestCase
{
    public function testACachedIconIsServedAsStored(): void
    {
        $feed = $this->feed();
        $feed->storeFavicon(
            'https://example.com/favicon.png',
            'png-bytes',
            'image/png',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );

        $favicon = (new CatalogFaviconSource(new MonogramFavicon()))->imageFor($feed);

        self::assertSame('png-bytes', $favicon->bytes);
        self::assertSame('image/png', $favicon->contentType);
    }

    public function testAFeedWithoutACachedIconGetsTheMonogram(): void
    {
        $feed = $this->feed();
        $monogram = new MonogramFavicon();

        $favicon = (new CatalogFaviconSource($monogram))->imageFor($feed);

        self::assertSame($monogram->render($feed), $favicon->bytes);
        self::assertSame(MonogramFavicon::CONTENT_TYPE, $favicon->contentType);
    }

    private function feed(): CatalogFeed
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        return new CatalogFeed($category, 'The Verge', 'https://example.com/feed.xml');
    }
}
