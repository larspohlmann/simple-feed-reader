<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Headline;
use PHPUnit\Framework\TestCase;

final class HeadlineTest extends TestCase
{
    public function testAnUntitledPostIsAnEmptyDerivedHeadline(): void
    {
        self::assertTrue(Headline::untitledPost()->isUntitledPost());
        self::assertEquals(Headline::derived(''), Headline::untitledPost());
    }

    public function testAnEmptyFeedHeadlineIsNoUntitledPost(): void
    {
        self::assertFalse(Headline::fromFeed('')->isUntitledPost());
    }

    public function testADerivedHeadlineWithTextIsNoUntitledPost(): void
    {
        self::assertFalse(Headline::derived('Post text')->isUntitledPost());
    }
}
