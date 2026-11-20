<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CanonicalItem;
use App\Entity\LemmyPost;
use App\Enum\CanonicalItemKind;
use App\Tests\Support\CanonicalItems;
use PHPUnit\Framework\TestCase;

final class LemmyPostTest extends TestCase
{
    public function testANewPostHasNoCommunity(): void
    {
        self::assertNull(new LemmyPost($this->buildItem())->getCommunity());
    }

    public function testThePostKeepsItsItem(): void
    {
        $item = $this->buildItem();

        self::assertSame($item, new LemmyPost($item)->getCanonicalItem());
    }

    private function buildItem(): CanonicalItem
    {
        return CanonicalItems::buildItem(CanonicalItemKind::Lemmy, 'https://lemmy.example/post/42');
    }
}
