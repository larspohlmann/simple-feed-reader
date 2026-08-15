<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Exception\ValidationException;
use App\Service\Search\SearchTerms;
use PHPUnit\Framework\TestCase;

final class SearchTermsTest extends TestCase
{
    public function testSplitsTheInputOnWhitespace(): void
    {
        $terms = SearchTerms::fromInput('angular signals');

        self::assertSame(['angular', 'signals'], $terms->terms);
    }

    public function testDropsLeadingAndTrailingWhitespace(): void
    {
        $terms = SearchTerms::fromInput("  angular\t");

        self::assertSame(['angular'], $terms->terms);
    }

    public function testCollapsesRepeatedWhitespaceBetweenTerms(): void
    {
        $terms = SearchTerms::fromInput("angular \n  signals");

        self::assertSame(['angular', 'signals'], $terms->terms);
    }

    public function testRejectsAnInputShorterThanThreeCharacters(): void
    {
        $this->expectException(ValidationException::class);

        SearchTerms::fromInput('  ng  ');
    }

    public function testAcceptsAnInputOfExactlyThreeCharacters(): void
    {
        $terms = SearchTerms::fromInput('ng2');

        self::assertSame(['ng2'], $terms->terms);
    }

    public function testRejectsAnInputLongerThanOneHundredCharacters(): void
    {
        $this->expectException(ValidationException::class);

        SearchTerms::fromInput(str_repeat('a', 101));
    }

    public function testKeepsOnlyTheFirstSixTerms(): void
    {
        $terms = SearchTerms::fromInput('one two three four five six seven eight');

        self::assertSame(['one', 'two', 'three', 'four', 'five', 'six'], $terms->terms);
    }
}
