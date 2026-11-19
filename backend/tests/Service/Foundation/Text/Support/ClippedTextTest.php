<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text\Support;

use App\Service\Foundation\Text\Support\ClippedText;
use PHPUnit\Framework\TestCase;

final class ClippedTextTest extends TestCase
{
    public function testTextOfExactlyTheLimitInCharactersIsKeptWhole(): void
    {
        self::assertSame('äöü', ClippedText::cutText('äöü', 3));
    }

    public function testLongerTextKeepsTheLimitAndAnEllipsis(): void
    {
        self::assertSame('äö…', ClippedText::cutText('äöü', 2));
    }

    public function testTheMarkerIsTheCallersToChoose(): void
    {
        self::assertSame('äö (cut)', ClippedText::cutText('äöü', 2, ' (cut)'));
    }

    public function testScrubbingReplacesAnInvalidByteBeforeTheClip(): void
    {
        self::assertSame('Caf? a…', ClippedText::cutScrubbedText("Caf\xE9 au lait", 6));
    }

    public function testTrimmingDropsTheWhitespaceTheClipEndsOn(): void
    {
        self::assertSame('Café…', ClippedText::cutTrimmedText("Café \t au lait", 6));
    }

    public function testTrimmingKeepsTextOfTheLimitWhole(): void
    {
        self::assertSame('Café ', ClippedText::cutTrimmedText('Café ', 5));
    }
}
