<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text\Support;

use App\Service\Foundation\Text\Support\EntryPlainText;
use PHPUnit\Framework\TestCase;

final class EntryPlainTextTest extends TestCase
{
    public function testCutGivesThePlainTextCutAtAWordBoundary(): void
    {
        self::assertSame('one two', EntryPlainText::cut('<p>one <b>two</b> three</p>', 8));
    }

    public function testCutOfNoTextIsEmpty(): void
    {
        self::assertSame('', EntryPlainText::cut(null, 300));
        self::assertSame('', EntryPlainText::cut('<p>n/a</p>', 300));
    }
}
