<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Support\HeadlineStems;
use PHPUnit\Framework\TestCase;

final class HeadlineStemsTest extends TestCase
{
    public function testBareStripsDiacriticsAndFoldsCase(): void
    {
        self::assertSame('elu', HeadlineStems::bare('élu'));
        self::assertSame('elu', HeadlineStems::bare('ÉLU'));
        self::assertSame('cafe resume naive', HeadlineStems::bare('café résumé naïve'));
    }

    public function testCutIsTheBareWordsFirstSixCharacters(): void
    {
        self::assertSame('bundes', HeadlineStems::cut('Bundesregierung'));
        self::assertSame('abcdef', HeadlineStems::cut('abcdef'));
        self::assertSame('uber', HeadlineStems::cut('über'));
    }

    public function testAWordInAnotherScriptIsCutAndMeasuredInCharacters(): void
    {
        self::assertSame('αθηναι', HeadlineStems::cut('αθηναϊκό'));
        self::assertSame('zołtos', HeadlineStems::cut('żółtość'));
    }

    public function testWordsUnderThreeLettersAreNotKept(): void
    {
        self::assertTrue(HeadlineStems::isKept('ban'));
        self::assertTrue(HeadlineStems::isKept('élu'));
        self::assertFalse(HeadlineStems::isKept('to'));
        self::assertFalse(HeadlineStems::isKept('ét'), 'two letters, though three bytes');
    }

    public function testNumbersOfTwoOrMoreDigitsAreKeptAndShorterOnesNot(): void
    {
        self::assertTrue(HeadlineStems::isKept('12'));
        self::assertFalse(HeadlineStems::isKept('9'));
    }
}
