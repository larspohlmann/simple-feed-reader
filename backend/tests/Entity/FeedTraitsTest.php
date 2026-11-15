<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\FeedTraits;
use App\Enum\FeedLanguage;
use App\Enum\FeedPlatform;
use PHPUnit\Framework\TestCase;

final class FeedTraitsTest extends TestCase
{
    public function testWithPlatformKeepsTheLanguage(): void
    {
        $traits = new FeedTraits(null, FeedLanguage::German)->withPlatform(FeedPlatform::Substack);

        self::assertSame(FeedPlatform::Substack, $traits->getPlatform());
        self::assertSame(FeedLanguage::German, $traits->getLanguage());
    }

    public function testWithLanguageKeepsThePlatform(): void
    {
        $traits = new FeedTraits(FeedPlatform::Substack)->withLanguage(FeedLanguage::French);

        self::assertSame(FeedPlatform::Substack, $traits->getPlatform());
        self::assertSame(FeedLanguage::French, $traits->getLanguage());
    }
}
