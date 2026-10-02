<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Support;

use App\Service\Recommendation\Support\ClippedText;
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
}
