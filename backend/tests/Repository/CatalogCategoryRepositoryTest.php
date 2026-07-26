<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogCategoryRepositoryTest extends DbTestCase
{
    public function testEnabledCategoriesComeBackInPositionOrderWithEnabledFeedsOnly(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $second = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $second->setPosition(1);
        $first = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $first->setPosition(0);
        $hidden = new CatalogCategory('hidden', 'Hidden', 'lock', '#000000');
        $hidden->setPosition(2);
        $hidden->setEnabled(false);

        $live = new CatalogFeed($first, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $live->setPosition(0);
        $retired = new CatalogFeed($first, 'Retired', 'https://example.com/gone.xml');
        $retired->setPosition(1);
        $retired->setEnabled(false);

        foreach ([$second, $first, $hidden, $live, $retired] as $row) {
            $em->persist($row);
        }
        $em->flush();
        // Doctrine returns already-managed entities from the identity map without
        // touching their to-many collections (UnitOfWork::createEntity() short-
        // circuits unless Query::HINT_REFRESH is set), so a fetch-joined query run
        // in the same process as the persist would leave $feeds looking empty. A
        // real request never hits this — each one gets a fresh EntityManager —
        // but the test must clear() to see what the repository actually returns.
        $em->clear();

        $repository = self::getContainer()->get(CatalogCategoryRepository::class);
        self::assertInstanceOf(CatalogCategoryRepository::class, $repository);

        $rows = $repository->findEnabledWithFeeds();

        self::assertSame(['Technology', 'Science'], array_map(
            static fn (CatalogCategory $c): string => $c->getName(),
            $rows,
        ));
        self::assertSame(['The Verge'], array_map(
            static fn (CatalogFeed $f): string => $f->getTitle(),
            $rows[0]->getEnabledFeeds(),
        ));
    }
}
