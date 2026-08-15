<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\WordBoundaries;
use PHPUnit\Framework\TestCase;

final class WordBoundariesTest extends TestCase
{
    public function testReplacesAHyphenWithASpace(): void
    {
        self::assertSame('E Mail', WordBoundaries::normalize('E-Mail'));
    }

    public function testLeavesATermWithoutPunctuationAlone(): void
    {
        self::assertSame('punk', WordBoundaries::normalize('punk'));
    }

    /**
     * One character in, one space out. The SQL side emits a REPLACE per
     * character and cannot collapse runs, so this side must not either — the
     * two normalizations are compared against each other, and a tidier
     * version here would stop "E--Mail" from matching itself.
     */
    public function testDoesNotCollapseTheRunsItCreates(): void
    {
        self::assertSame('E  Mail', WordBoundaries::normalize('E--Mail'));
    }

    public function testLeavesTheLikeWildcardsAlone(): void
    {
        // '%' and '_' are LikePattern's business, not a word boundary.
        self::assertSame('100%', WordBoundaries::normalize('100%'));
        self::assertSame('snake_case', WordBoundaries::normalize('snake_case'));
    }

    public function testTreatsTheEscapeCharacterAsABoundary(): void
    {
        // '!' is both LikePattern's escape character and sentence punctuation.
        // Normalizing first is what stops it ever reaching escape().
        self::assertSame('wow ', WordBoundaries::normalize('wow!'));
    }

    public function testReportsWhetherATermCarriesAnyBoundaryCharacter(): void
    {
        self::assertTrue(WordBoundaries::areIn('E-Mail'));
        self::assertTrue(WordBoundaries::areIn('TCP/IP'));
        self::assertFalse(WordBoundaries::areIn('punk'));
        self::assertFalse(WordBoundaries::areIn('100%'));
    }
}
