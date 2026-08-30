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
        // This guaranteed null is the point of the test.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertNull(MagazineStyle::tryFrom('cards'));
    }
}
