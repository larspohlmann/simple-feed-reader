<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\MonogramFavicon;
use PHPUnit\Framework\TestCase;

final class MonogramFaviconTest extends TestCase
{
    private function feed(string $title): CatalogFeed
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        return new CatalogFeed($category, $title, 'https://example.com/feed.xml');
    }

    public function testRendersTheFirstLetterOnTheCategoryColour(): void
    {
        $svg = (new MonogramFavicon())->render($this->feed('The Verge'));

        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringContainsString('#3b82f6', $svg);
        self::assertStringContainsString('>T<', $svg);
    }

    public function testIsDeterministic(): void
    {
        $monogram = new MonogramFavicon();

        self::assertSame(
            $monogram->render($this->feed('Ars Technica')),
            $monogram->render($this->feed('Ars Technica')),
        );
    }

    public function testEscapesATitleThatWouldOtherwiseInjectMarkup(): void
    {
        $svg = (new MonogramFavicon())->render($this->feed('<script>'));

        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('&lt;', $svg);
    }

    public function testFallsBackToAQuestionMarkForAnEmptyTitle(): void
    {
        $svg = (new MonogramFavicon())->render($this->feed(''));

        self::assertStringContainsString('>?<', $svg);
    }
}
