<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Stemming;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\Factory\SnowballStemmerFactory;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Foundation\Stemming\WordStemmer;
use PHPUnit\Framework\TestCase;

final class WordStemmerTest extends TestCase
{
    private WordStemmer $stemmer;

    protected function setUp(): void
    {
        $this->stemmer = new WordStemmer(new SnowballStemmerFactory());
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

        self::assertSame(WordStems::bare($stem), $stem);
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
