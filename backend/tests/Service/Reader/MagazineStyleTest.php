<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\MagazineStyle;
use PHPUnit\Framework\TestCase;

final class MagazineStyleTest extends TestCase
{
    public function testTheTwoStylesAreTheOnlyOnes(): void
    {
        self::assertSame(
            ['boxed', 'airy'],
            array_map(static fn (MagazineStyle $style): string => $style->value, MagazineStyle::cases()),
        );
    }

    public function testAnUnknownValueIsNotAStyle(): void
    {
        // PHPStan proves 'cards' matches neither backed case, so it already
        // knows tryFrom() returns null here (staticMethod.alreadyNarrowedType)
        // — that guarantee is exactly what this test exists to pin down.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertNull(MagazineStyle::tryFrom('cards'));
    }
}
