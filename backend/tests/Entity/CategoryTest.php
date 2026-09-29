<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testCategoryExposesIdentity(): void
    {
        $category = new Category('politics', '');

        self::assertNull($category->getId());
        self::assertSame('politics', $category->getCanonicalKey());
        self::assertSame('', $category->getScheme());
    }

    public function testEntryCategoryCarriesPositionAndLabel(): void
    {
        $category = new Category('politics', '');
        $createdAt = new \DateTimeImmutable('2026-07-01 10:00:00');
        $entry = new Entry(new Feed('https://example.com/feed.xml'), 'guid-1', null, 'Title', $createdAt, $createdAt);

        $link = new EntryCategory($entry, $category, 2, 'Politics');

        self::assertSame($category, $link->getCategory());
        self::assertSame(2, $link->getPosition());
        self::assertSame('Politics', $link->getLabel());
    }
}
