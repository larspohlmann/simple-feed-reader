<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PostCommunity;
use PHPUnit\Framework\TestCase;

final class PostCommunityTest extends TestCase
{
    public function testTheShortNameIsThePartBeforeTheHost(): void
    {
        $community = PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology');

        self::assertSame('technology', $community->shortName());
    }

    public function testTheIdentityIsLowerCased(): void
    {
        $community = PostCommunity::of('Technology@Lemmy.World', 'https://lemmy.world/c/technology');

        self::assertSame('technology@lemmy.world', $community->identity);
        self::assertSame('https://lemmy.world/c/technology', $community->url);
    }
}
