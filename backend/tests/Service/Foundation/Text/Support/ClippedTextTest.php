<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\ClippedText;
use PHPUnit\Framework\TestCase;

final class ClippedTextTest extends TestCase
{
    public function testTextOfExactlyTheLimitInCharactersIsKeptWhole(): void
    {
        self::assertSame('äöü', ClippedText::of('äöü', 3));
    }

    public function testLongerTextKeepsTheLimitAndAnEllipsis(): void
    {
        self::assertSame('äö…', ClippedText::of('äöü', 2));
    }

    public function testTheMarkerIsTheCallersToChoose(): void
    {
        self::assertSame('äö (cut)', ClippedText::of('äöü', 2, ' (cut)'));
    }

    public function testScrubbingReplacesAnInvalidByteBeforeTheClip(): void
    {
        self::assertSame('Caf? a…', ClippedText::ofScrubbed("Caf\xE9 au lait", 6));
    }

    public function testTrimmingDropsTheWhitespaceTheClipEndsOn(): void
    {
        self::assertSame('Café…', ClippedText::trimmedOf("Café \t au lait", 6));
    }

    public function testTrimmingKeepsTextOfTheLimitWhole(): void
    {
        self::assertSame('Café ', ClippedText::trimmedOf('Café ', 5));
    }
}
