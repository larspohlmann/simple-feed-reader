<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Scoring\Support;

use App\Service\Curation\Recommendation\Scoring\Support\FittingPrefix;
use PHPUnit\Framework\TestCase;

final class FittingPrefixTest extends TestCase
{
    public function testATextThatFitsStaysWhole(): void
    {
        self::assertSame('abcdefgh', FittingPrefix::cutToFit('abcdefgh', static fn (string $prefix): bool => true));
    }

    public function testTheCutIsTheLongestPrefixThatFits(): void
    {
        $fits = static fn (string $prefix): bool => \strlen($prefix) <= 5;

        self::assertSame('abcde', FittingPrefix::cutToFit('abcdefgh', $fits));
    }

    /** By characters, never inside one: 'äö' is 4 bytes, 'äöü' 6. */
    public function testTheCutNeverSplitsAMultiByteCharacter(): void
    {
        $fits = static fn (string $prefix): bool => \strlen($prefix) <= 5;

        self::assertSame('äö', FittingPrefix::cutToFit('äöüß', $fits));
    }

    /** The cut is judged on the prefix itself, not only on its length. */
    public function testTheCutStopsBeforeTheFirstCharacterThatDoesNotFit(): void
    {
        $fits = static fn (string $prefix): bool => !str_contains($prefix, 'd');

        self::assertSame('abc', FittingPrefix::cutToFit('abcdefgh', $fits));
    }

    public function testWhenOnlyTheEmptyPrefixFitsTheCutIsEmpty(): void
    {
        self::assertSame('', FittingPrefix::cutToFit('abc', static fn (string $prefix): bool => '' === $prefix));
    }
}
