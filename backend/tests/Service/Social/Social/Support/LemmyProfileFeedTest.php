<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\LemmyProfileFeed;
use PHPUnit\Framework\TestCase;

final class LemmyProfileFeedTest extends TestCase
{
    public function testACommunityFeedIsBuiltFromTheCommunityAndHost(): void
    {
        self::assertSame('https://feddit.example/feeds/c/rss.xml', LemmyProfileFeed::buildUrl('rss', 'feddit.example'));
    }

    public function testAUserAndACommunityOfOneNameAreTwoIdentities(): void
    {
        self::assertSame(
            'ada@feddit.example',
            LemmyProfileFeed::parseIdentity('https://feddit.example/feeds/c/ada.xml'),
        );
        self::assertSame(
            '@ada@feddit.example',
            LemmyProfileFeed::parseIdentity('https://feddit.example/feeds/u/ada.xml'),
        );
    }

    public function testAUserIdentityNamesTheUser(): void
    {
        self::assertSame('ada@feddit.example', LemmyProfileFeed::parseUser('@ada@feddit.example'));
        self::assertNull(LemmyProfileFeed::parseUser('ada@feddit.example'));
    }
}
