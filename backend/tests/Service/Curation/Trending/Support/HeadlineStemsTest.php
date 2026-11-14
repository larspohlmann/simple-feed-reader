<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Support\HeadlineStems;
use PHPUnit\Framework\TestCase;

final class HeadlineStemsTest extends TestCase
{
    public function testDiacriticsAndUmlautsAreStrippedAndSharpSIsCaseFolded(): void
    {
        self::assertSame(['uber', 'die', 'strass'], self::stems('Über die Straße'));
        self::assertSame(['cafe', 'resume', 'naive'], self::stems('Café Résumé naïve'));
    }

    public function testCompatibilityCharactersDecomposeToPlainLetters(): void
    {
        self::assertSame(['financ'], self::stems('ＦＩＮＡＮＣＩＡＬ'));
    }

    public function testEveryWordIsCutToItsFirstSixCharacters(): void
    {
        self::assertSame(['abcdef', 'ukrain'], self::stems('abcdef abcdefg Ukraine Ukrainian'));
    }

    public function testAWordInAnotherScriptIsCutAndMeasuredInCharacters(): void
    {
        self::assertSame(['αθηναι'], self::stems('Το Αθηναϊκό'));
        self::assertSame(['zołtos'], self::stems('Żółtość'));
    }

    public function testWordsUnderThreeLettersAreDropped(): void
    {
        self::assertSame(['ban'], self::stems('EU to ban AI'));
    }

    public function testNumbersOfTwoOrMoreDigitsAreKeptAndShorterOnesDropped(): void
    {
        self::assertSame(
            ['summit', '2026', 'deaths', '12', 'hurt'],
            self::stems('G7 summit 2026: 9 deaths, 12 hurt'),
        );
    }

    public function testStemsAreDistinctInReadingOrder(): void
    {
        self::assertSame(['fire', 'statio'], self::stems('Fire fire FIRE station'));
    }

    public function testTextWithoutWordsHasNoStems(): void
    {
        self::assertSame([], self::stems(' — !! '));
    }

    public function testWordsAreFoldedAndSplitButNotCut(): void
    {
        self::assertSame(
            ['vereinigte', 'staaten', 'etats', 'unis'],
            HeadlineStems::words('Vereinigte Staaten, États-Unis'),
        );
    }

    /** @return list<string> */
    private static function stems(string $text): array
    {
        return HeadlineStems::ofWords(HeadlineStems::words($text));
    }
}
