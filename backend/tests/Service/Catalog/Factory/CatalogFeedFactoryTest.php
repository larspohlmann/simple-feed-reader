<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog\Factory;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFeedDetails;
use App\Service\Catalog\Factory\CatalogFeedFactory;
use App\Tests\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CatalogFeedFactoryTest extends DbTestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function flags(): iterable
    {
        yield 'disabled and locked' => [false, true];
        yield 'enabled and unlocked' => [true, false];
    }

    #[DataProvider('flags')]
    public function testANewFeedTakesItsDetailsAndGoesLastInItsCategory(bool $enabled, bool $locked): void
    {
        $category = new CatalogCategory('tech', 'Tech', 'chip', '#333333');
        $this->em->persist($category);
        $this->em->persist(new CatalogFeed($category, 'First', 'https://first.example/feed.xml'));
        $this->em->flush();
        $next = $this->feeds()->nextPositionInCategory($category->requireId());
        self::assertGreaterThan(0, $next);

        $feed = (new CatalogFeedFactory($this->categories(), $this->feeds()))->create(new CatalogFeedDetails(
            $category->requireId(),
            'Second',
            'https://second.example/feed.xml',
            'https://second.example',
            'The second feed',
            'scraped',
            $enabled,
            $locked,
        ));

        self::assertSame('Second', $feed->getTitle());
        self::assertSame('https://second.example', $feed->getSiteUrl());
        self::assertSame('The second feed', $feed->getDescription());
        self::assertSame('scraped', $feed->getSourceFormat());
        self::assertSame($enabled, $feed->isEnabled());
        self::assertSame($locked, $feed->isLocked());
        self::assertSame($next, $feed->getPosition());
    }

    private function categories(): CatalogCategoryRepository
    {
        /** @var CatalogCategoryRepository $categories */
        $categories = self::getContainer()->get(CatalogCategoryRepository::class);

        return $categories;
    }

    private function feeds(): CatalogFeedRepository
    {
        /** @var CatalogFeedRepository $feeds */
        $feeds = self::getContainer()->get(CatalogFeedRepository::class);

        return $feeds;
    }
}
