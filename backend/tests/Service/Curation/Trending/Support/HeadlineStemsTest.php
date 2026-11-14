<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Support\HeadlineStems;
use PHPUnit\Framework\TestCase;

final class HeadlineStemsTest extends TestCase
{
    public function testDiacriticsAndUmlautsAreStrippedAndSharpSIsCaseFolded(): void
    {
        self::assertSame(['uber', 'die', 'strass'], HeadlineStems::of('Über die Straße'));
        self::assertSame(['cafe', 'resume', 'naive'], HeadlineStems::of('Café Résumé naïve'));
    }

    public function testCompatibilityCharactersDecomposeToPlainLetters(): void
    {
        self::assertSame(['financ'], HeadlineStems::of('ＦＩＮＡＮＣＩＡＬ'));
    }

    public function testEveryWordIsCutToItsFirstSixCharacters(): void
    {
        self::assertSame(['abcdef', 'ukrain'], HeadlineStems::of('abcdef abcdefg Ukraine Ukrainian'));
    }

    public function testAWordInAnotherScriptIsCutAndMeasuredInCharacters(): void
    {
        self::assertSame(['αθηναι'], HeadlineStems::of('Το Αθηναϊκό'));
        self::assertSame(['zołtos'], HeadlineStems::of('Żółtość'));
    }

    public function testWordsUnderThreeLettersAreDropped(): void
    {
        self::assertSame(['ban'], HeadlineStems::of('EU to ban AI'));
    }

    public function testNumbersOfTwoOrMoreDigitsAreKeptAndShorterOnesDropped(): void
    {
        self::assertSame(
            ['summit', '2026', 'deaths', '12', 'hurt'],
            HeadlineStems::of('G7 summit 2026: 9 deaths, 12 hurt'),
        );
    }

    public function testStemsAreDistinctInReadingOrder(): void
    {
        self::assertSame(['fire', 'statio'], HeadlineStems::of('Fire fire FIRE station'));
    }

    public function testTextWithoutWordsHasNoStems(): void
    {
        self::assertSame([], HeadlineStems::of(' — !! '));
    }
}
