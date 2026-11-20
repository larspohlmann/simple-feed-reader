<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CanonicalItem;
use App\Enum\CanonicalItemKind;
use App\Tests\Support\CanonicalItems;
use PHPUnit\Framework\TestCase;

final class CanonicalItemTest extends TestCase
{
    public function testNullScoreKeepsTheStoredOne(): void
    {
        $item = self::buildItem();
        $item->recordScore(4);

        $item->recordScore(null);

        self::assertSame(4, $item->getScore());
    }

    public function testANewScoreReplacesTheStoredOne(): void
    {
        $item = self::buildItem();
        $item->recordScore(4);

        $item->recordScore(1);

        self::assertSame(1, $item->getScore());
    }

    public function testNullCommentCountKeepsTheStoredOne(): void
    {
        $item = self::buildItem();
        $item->recordCommentCount(6);

        $item->recordCommentCount(null);

        self::assertSame(6, $item->getCommentCount());
    }

    public function testANewCommentCountReplacesTheStoredOne(): void
    {
        $item = self::buildItem();
        $item->recordCommentCount(6);

        $item->recordCommentCount(2);

        self::assertSame(2, $item->getCommentCount());
    }

    public function testSelfReplyCountOnlyGrows(): void
    {
        $item = self::buildItem();
        $item->recordSelfReplyCount(5);

        $item->recordSelfReplyCount(3);
        self::assertSame(5, $item->getSelfReplyCount());

        $item->recordSelfReplyCount(8);
        self::assertSame(8, $item->getSelfReplyCount());
    }

    public function testANewItemKnowsNoCounts(): void
    {
        $item = self::buildItem();

        self::assertNull($item->getScore());
        self::assertNull($item->getCommentCount());
        self::assertSame(0, $item->getSelfReplyCount());
    }

    public function testTheKeyHashIsTheSha256OfTheKey(): void
    {
        $key = 'https://lemmy.world/post/7';

        self::assertSame(hash('sha256', $key), CanonicalItem::hashKey($key));
    }

    private static function buildItem(): CanonicalItem
    {
        return CanonicalItems::buildItem(CanonicalItemKind::Lemmy, 'https://lemmy.world/post/7');
    }
}
