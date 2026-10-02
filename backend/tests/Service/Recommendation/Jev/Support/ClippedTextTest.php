<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Jev\Support\ClippedText;
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
}
