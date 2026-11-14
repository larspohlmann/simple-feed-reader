<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\SocialSearchPaths;
use PHPUnit\Framework\TestCase;

final class SocialSearchPathsTest extends TestCase
{
    public function testALemmySearchAsksForNewPostsEverywhere(): void
    {
        self::assertSame(
            '/api/v3/search?q=rss%20reader%20%7C%7C%20atom&type_=Posts&sort=New&listing_type=All&limit=50',
            SocialSearchPaths::lemmy('rss reader || atom'),
        );
    }
}
