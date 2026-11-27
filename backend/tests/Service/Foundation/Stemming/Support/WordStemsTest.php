<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Stemming\Support;

use App\Service\Foundation\Stemming\Support\WordStems;
use PHPUnit\Framework\TestCase;

final class WordStemsTest extends TestCase
{
    public function testBareStripsDiacriticsAndFoldsCase(): void
    {
        self::assertSame('elu', WordStems::bare('élu'));
        self::assertSame('elu', WordStems::bare('ÉLU'));
        self::assertSame('cafe resume naive', WordStems::bare('café résumé naïve'));
    }

    public function testCutIsTheBareWordsFirstSixCharacters(): void
    {
        self::assertSame('bundes', WordStems::cut('Bundesregierung'));
        self::assertSame('abcdef', WordStems::cut('abcdef'));
        self::assertSame('uber', WordStems::cut('über'));
    }

    public function testAWordInAnotherScriptIsCutAndMeasuredInCharacters(): void
    {
        self::assertSame('αθηναι', WordStems::cut('αθηναϊκό'));
        self::assertSame('zołtos', WordStems::cut('żółtość'));
    }

    public function testWordsUnderThreeLettersAreNotKept(): void
    {
        self::assertTrue(WordStems::isKept('ban'));
        self::assertTrue(WordStems::isKept('élu'));
        self::assertFalse(WordStems::isKept('to'));
        self::assertFalse(WordStems::isKept('ét'), 'two letters, though three bytes');
    }

    public function testNumbersOfTwoOrMoreDigitsAreKeptAndShorterOnesNot(): void
    {
        self::assertTrue(WordStems::isKept('12'));
        self::assertFalse(WordStems::isKept('9'));
    }
}
