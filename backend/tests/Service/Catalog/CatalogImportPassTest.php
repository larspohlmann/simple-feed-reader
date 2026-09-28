<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogDocumentCategory;
use App\Service\Catalog\CatalogDocumentFeed;
use App\Service\Catalog\CatalogImportPass;
use App\Service\Catalog\ParsedCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CatalogImportPassTest extends TestCase
{
    public function testANewDocumentPersistsEveryRowAndCountsIt(): void
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $row) use (&$persisted): void {
            $persisted[] = $row;
        });
        $em->expects($this->never())->method('remove');
        $pass = new CatalogImportPass($em, [], []);

        $pass->apply(new ParsedCatalog([
            new CatalogDocumentCategory('tech', 'Tech', 'memory', '#3b82f6', [
                new CatalogDocumentFeed('One', 'https://one.example.com/rss.xml', null, null, 'xml'),
            ]),
        ]));

        self::assertCount(2, $persisted);
        [$category, $feed] = $persisted;
        self::assertInstanceOf(CatalogCategory::class, $category);
        self::assertInstanceOf(CatalogFeed::class, $feed);
        self::assertSame($category, $feed->getCategory());
        self::assertSame(1, $pass->result()->categoriesCreated);
        self::assertSame(1, $pass->result()->feedsCreated);
        self::assertSame(0, $pass->result()->categoriesUpdated);
        self::assertSame(0, $pass->result()->feedsUpdated);
    }

    public function testRemovingUnmentionedRowsSparesLockedFeedsAndTheCategoriesHoldingThem(): void
    {
        $kept = new CatalogCategory('kept', 'Kept', 'memory', '#3b82f6');
        $holding = new CatalogCategory('holding', 'Holding', 'memory', '#3b82f6');
        $dropped = new CatalogCategory('dropped', 'Dropped', 'memory', '#3b82f6');
        $lockedFeed = new CatalogFeed($holding, 'Locked', 'https://locked.example.com/rss.xml');
        $lockedFeed->setLocked(true);
        $staleFeed = new CatalogFeed($kept, 'Stale', 'https://stale.example.com/rss.xml');
        $removed = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $row) use (&$removed): void {
            $removed[] = $row;
        });
        $pass = new CatalogImportPass($em, [$kept, $holding, $dropped], [$lockedFeed, $staleFeed]);
        $pass->apply(new ParsedCatalog([new CatalogDocumentCategory('kept', 'Kept', 'memory', '#3b82f6', [])]));

        $pass->removeUnmentioned();

        self::assertSame([$staleFeed, $dropped], $removed);
        self::assertSame(1, $pass->result()->feedsRemoved);
        self::assertSame(1, $pass->result()->categoriesRemoved);
        self::assertSame(2, $pass->result()->lockedSkipped);
        self::assertSame(1, $pass->result()->categoriesUpdated);
    }

    public function testEveryCategoryHoldingALockedFeedIsSpared(): void
    {
        $first = new CatalogCategory('first', 'First', 'memory', '#3b82f6');
        $second = new CatalogCategory('second', 'Second', 'memory', '#3b82f6');
        $firstLocked = new CatalogFeed($first, 'First locked', 'https://first.example.com/rss.xml');
        $firstLocked->setLocked(true);
        $secondLocked = new CatalogFeed($second, 'Second locked', 'https://second.example.com/rss.xml');
        $secondLocked->setLocked(true);
        $removed = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $row) use (&$removed): void {
            $removed[] = $row;
        });
        $pass = new CatalogImportPass($em, [$first, $second], [$firstLocked, $secondLocked]);
        $pass->apply(new ParsedCatalog([]));

        $pass->removeUnmentioned();

        self::assertSame([], $removed);
        self::assertSame(0, $pass->result()->categoriesRemoved);
        self::assertSame(4, $pass->result()->lockedSkipped);
    }
}
