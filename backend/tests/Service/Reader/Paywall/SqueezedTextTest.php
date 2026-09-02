<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Reader\Paywall\SqueezedText;
use PHPUnit\Framework\TestCase;

final class SqueezedTextTest extends TestCase
{
    public function testRemovesEveryWhitespaceIncludingNewlinesAndNoBreakSpaces(): void
    {
        self::assertSame('abc', SqueezedText::of("  a \n\t b\u{00A0}\u{00A0}c  "));
    }

    public function testLeavesTextWithoutWhitespaceAlone(): void
    {
        self::assertSame('Wörter–und.Zeichen', SqueezedText::of('Wörter–und.Zeichen'));
    }

    public function testAnAllWhitespaceStringBecomesEmpty(): void
    {
        self::assertSame('', SqueezedText::of(" \n\u{00A0}"));
    }
}
