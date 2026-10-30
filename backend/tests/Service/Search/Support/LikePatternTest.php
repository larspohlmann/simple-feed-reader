<?php

declare(strict_types=1);

namespace App\Tests\Service\Search\Support;

use App\Service\Search\Support\LikePattern;
use PHPUnit\Framework\TestCase;

final class LikePatternTest extends TestCase
{
    public function testWrapsThePlainTermInWildcards(): void
    {
        self::assertSame('%angular%', LikePattern::containing('angular'));
    }

    public function testEscapesAPercentSignSoItMatchesLiterally(): void
    {
        self::assertSame('%100!%%', LikePattern::containing('100%'));
    }

    public function testEscapesAnUnderscoreSoItMatchesLiterally(): void
    {
        self::assertSame('%a!_b%', LikePattern::containing('a_b'));
    }

    public function testEscapesTheEscapeCharacterItself(): void
    {
        self::assertSame('%wow!!%', LikePattern::containing('wow!'));
    }

    /**
     * The escape character has to be doubled BEFORE the wildcards gain their
     * own escape, or the second pass escapes the first pass's output and a
     * term containing "!" stops matching itself.
     */
    public function testEscapesTheEscapeCharacterBeforeTheWildcards(): void
    {
        self::assertSame('%!!!%%', LikePattern::containing('!%'));
    }

    public function testAnAsciiTermWithoutBoundariesIsItsOwnAnchor(): void
    {
        self::assertSame('C++', LikePattern::anchor('C++'));
    }

    public function testAnAsciiTermWithABoundaryIsAnchoredOnItsLongestAlphanumericStretch(): void
    {
        self::assertSame('Mail', LikePattern::anchor('E-Mail'));
    }

    public function testANonAsciiTermIsAnchoredOnItsLongestAsciiStretch(): void
    {
        self::assertSame('bersee', LikePattern::anchor('Übersee'));
        self::assertSame('Fluss', LikePattern::anchor('über den Fluss'));
    }

    public function testATermWithoutAsciiLettersHasNoAnchor(): void
    {
        self::assertNull(LikePattern::anchor('αθηνα'));
    }
}
