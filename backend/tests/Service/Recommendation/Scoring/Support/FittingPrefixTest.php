<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Scoring\Support\FittingPrefix;
use PHPUnit\Framework\TestCase;

final class FittingPrefixTest extends TestCase
{
    public function testATextThatFitsStaysWhole(): void
    {
        self::assertSame('abcdefgh', FittingPrefix::of('abcdefgh', static fn (string $prefix): bool => true));
    }

    public function testTheCutIsTheLongestPrefixThatFits(): void
    {
        $fits = static fn (string $prefix): bool => \strlen($prefix) <= 5;

        self::assertSame('abcde', FittingPrefix::of('abcdefgh', $fits));
    }

    /** By characters, never inside one: 'äö' is 4 bytes, 'äöü' 6. */
    public function testTheCutNeverSplitsAMultiByteCharacter(): void
    {
        $fits = static fn (string $prefix): bool => \strlen($prefix) <= 5;

        self::assertSame('äö', FittingPrefix::of('äöüß', $fits));
    }

    /** The cut is judged on the prefix itself, not only on its length. */
    public function testTheCutStopsBeforeTheFirstCharacterThatDoesNotFit(): void
    {
        $fits = static fn (string $prefix): bool => !str_contains($prefix, 'd');

        self::assertSame('abc', FittingPrefix::of('abcdefgh', $fits));
    }

    public function testWhenOnlyTheEmptyPrefixFitsTheCutIsEmpty(): void
    {
        self::assertSame('', FittingPrefix::of('abc', static fn (string $prefix): bool => '' === $prefix));
    }
}
