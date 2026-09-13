<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\EntryCategory;
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
        $entry = $this->createStub(\App\Entity\Entry::class);

        $link = new EntryCategory($entry, $category, 2, 'Politics');

        self::assertSame($category, $link->getCategory());
        self::assertSame(2, $link->getPosition());
        self::assertSame('Politics', $link->getLabel());
    }
}
