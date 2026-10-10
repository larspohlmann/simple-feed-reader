<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\EntryTitle;
use PHPUnit\Framework\TestCase;

final class EntryTitleTest extends TestCase
{
    public function testAFeedTitleWinsAndIsNotDerived(): void
    {
        $title = EntryTitle::of('A <em>real</em> title', '<p>Body text here.</p>');

        self::assertSame('A real title', $title->text);
        self::assertFalse($title->derived);
    }

    public function testATitleLessItemTakesItsTitleFromTheBody(): void
    {
        $title = EntryTitle::of(null, '<p>Body text here. And more.</p>');

        self::assertSame('Body text here.', $title->text);
        self::assertTrue($title->derived);
    }

    public function testABlankFeedTitleCountsAsNone(): void
    {
        self::assertTrue(EntryTitle::of('   ', '<p>Body text here.</p>')->derived);
    }

    public function testAnItemWithNeitherIsUntitledAndNotDerived(): void
    {
        $title = EntryTitle::of(null, null);

        self::assertSame('(untitled)', $title->text);
        self::assertFalse($title->derived);
    }
}
