<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\LikePattern;
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
}
