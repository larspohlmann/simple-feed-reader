<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineStemmer;
use App\Service\Curation\Trending\Support\HeadlineStems;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\TestCase;

final class HeadlineStemmerTest extends TestCase
{
    private HeadlineStemmer $stemmer;

    protected function setUp(): void
    {
        $this->stemmer = TrendingWiring::stemmer();
    }

    public function testGermanCompoundsKeepTheirOwnStems(): void
    {
        $stems = array_map(
            fn (string $word): string => $this->stemmer->stem($word, FeedLanguage::German),
            ['bundestag', 'bundesliga', 'bundesregierung'],
        );

        self::assertCount(3, array_unique($stems));
    }

    public function testAGermanGenitiveStemsLikeItsNominative(): void
    {
        self::assertSame(
            $this->stemmer->stem('russland', FeedLanguage::German),
            $this->stemmer->stem('russlands', FeedLanguage::German),
        );
    }

    public function testDiacriticsAreStrippedAfterStemming(): void
    {
        $stem = $this->stemmer->stem('élections', FeedLanguage::French);

        self::assertSame(HeadlineStems::bare($stem), $stem);
        self::assertSame(
            $this->stemmer->stem('union', FeedLanguage::Spanish),
            $this->stemmer->stem('Unión', FeedLanguage::Spanish),
        );
    }

    public function testTheSharpSStemsLikeItsDoubleS(): void
    {
        self::assertSame(
            $this->stemmer->stem('grossbritannien', FeedLanguage::German),
            $this->stemmer->stem('Großbritannien', FeedLanguage::German),
        );
    }

    public function testAWordWithoutALanguageIsCutToSixCharacters(): void
    {
        self::assertSame('bundes', $this->stemmer->stem('Bundesregierung', null));
    }

    public function testAGermanVerbFormEndingInTStemsLikeItsInfinitive(): void
    {
        self::assertSame(
            $this->stemmer->stem('verhandeln', FeedLanguage::German),
            $this->stemmer->stem('verhandelt', FeedLanguage::German),
        );
    }

    public function testAGermanVerbFormStemsLikeItsNounDerivation(): void
    {
        self::assertSame(
            $this->stemmer->stem('Nominierung', FeedLanguage::German),
            $this->stemmer->stem('nominiert', FeedLanguage::German),
        );
    }

    public function testAnUppercaseNounIsNotTakenForAVerbForm(): void
    {
        self::assertSame('macht', $this->stemmer->stem('Macht', FeedLanguage::German));
    }

    public function testTheVerbRuleLeavesEnglishAlone(): void
    {
        self::assertSame('vote', $this->stemmer->stem('voted', FeedLanguage::English));
    }

    public function testAWordWithoutALanguageGetsNoVerbRule(): void
    {
        self::assertSame('verhan', $this->stemmer->stem('verhandelt', null));
    }
}
