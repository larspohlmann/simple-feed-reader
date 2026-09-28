<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog\Factory;

use App\Entity\CatalogCategory;
use App\Repository\CatalogCategoryRepository;
use App\Service\Catalog\CatalogCategoryDetails;
use App\Service\Catalog\Factory\CatalogCategoryFactory;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CatalogCategoryFactoryTest extends DbTestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function flags(): iterable
    {
        yield 'disabled and locked' => [false, true];
        yield 'enabled and unlocked' => [true, false];
    }

    #[DataProvider('flags')]
    public function testANewCategoryTakesItsDetailsAndGoesLast(bool $enabled, bool $locked): void
    {
        $this->em->persist(new CatalogCategory('existing', 'Existing', 'star', '#111111'));
        $this->em->flush();
        $next = $this->categories()->nextPosition();
        self::assertGreaterThan(0, $next);

        $category = (new CatalogCategoryFactory($this->categories()))->create(
            new CatalogCategoryDetails('news', 'News', 'globe', '#222222', $enabled, $locked),
        );

        self::assertSame('news', $category->getKey());
        self::assertSame($enabled, $category->isEnabled());
        self::assertSame($locked, $category->isLocked());
        self::assertSame($next, $category->getPosition());
    }

    private function categories(): CatalogCategoryRepository
    {
        /** @var CatalogCategoryRepository $categories */
        $categories = self::getContainer()->get(CatalogCategoryRepository::class);

        return $categories;
    }
}
