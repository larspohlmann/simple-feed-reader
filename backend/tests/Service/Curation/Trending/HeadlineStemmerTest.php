<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Factory\SnowballStemmerFactory;
use App\Service\Curation\Trending\HeadlineStemmer;
use PHPUnit\Framework\TestCase;

final class HeadlineStemmerTest extends TestCase
{
    private HeadlineStemmer $stemmer;

    protected function setUp(): void
    {
        $this->stemmer = new HeadlineStemmer(new SnowballStemmerFactory());
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
        self::assertSame('elect', $this->stemmer->stem('élections', FeedLanguage::French));
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
}
