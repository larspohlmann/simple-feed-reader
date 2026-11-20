<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CanonicalItem;
use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use App\Enum\CanonicalItemKind;
use PHPUnit\Framework\TestCase;

final class LemmyPostTest extends TestCase
{
    public function testANewPostHasNoCommunity(): void
    {
        self::assertNull(new LemmyPost($this->item())->getCommunity());
    }

    public function testAPostInACommunityNamesIt(): void
    {
        $post = new LemmyPost($this->item());

        $post->placeIn(PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'));

        self::assertEquals(
            PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'),
            $post->getCommunity(),
        );
    }

    public function testThePostKeepsItsItem(): void
    {
        $item = $this->item();

        self::assertSame($item, new LemmyPost($item)->getCanonicalItem());
    }

    private function item(): CanonicalItem
    {
        return new CanonicalItem(CanonicalItemKind::Lemmy, 'https://lemmy.example/post/42');
    }
}
