<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\StopWords\StopWordsInterface;
use App\Service\Ingestion\Language\StopWordVote;
use App\Tests\Support\LanguageWiring;
use PHPUnit\Framework\TestCase;

final class StopWordVoteTest extends TestCase
{
    public function testTheLanguageHoldingMostStopwordsWins(): void
    {
        self::assertSame(FeedLanguage::German, self::vote('Aus dem Archiv!'));
        self::assertSame(FeedLanguage::English, self::vote('Palantir exec nominated to lead CISA'));
        self::assertSame(FeedLanguage::French, self::vote('Je ne sais pas ce que tu veux'));
        self::assertSame(FeedLanguage::Spanish, self::vote('El gobierno no lo sabe'));
    }

    public function testATieHasNoVote(): void
    {
        self::assertNull(self::vote('the and der die'));
    }

    public function testATextWithoutStopwordsHasNoVote(): void
    {
        self::assertNull(self::vote('Hehe'));
    }

    public function testAOneLetterWordDoesNotVote(): void
    {
        self::assertNull(self::vote("Silicon Valley plots Jensen Huang's comeback"));
    }

    public function testARepeatedStopwordCountsEachTime(): void
    {
        self::assertSame(FeedLanguage::English, self::vote('the the der'));
    }

    public function testAWordCountsForEveryListHoldingIt(): void
    {
        self::assertSame(FeedLanguage::German, self::vote('in der'));
    }

    public function testACyrillicTextHasNoVote(): void
    {
        self::assertNull(self::vote('Президент заявил в понедельник'));
    }

    public function testTheVoteFoldsCase(): void
    {
        self::assertSame(FeedLanguage::German, self::vote('Aus Dem Archiv'));
    }

    public function testAWordInCapitalsDoesNotVote(): void
    {
        self::assertNull(self::vote('Laravel AI SDK'));
        self::assertSame(FeedLanguage::English, self::vote('share AI generated memes with the team'));
    }

    public function testAShoutedTextHasNoVote(): void
    {
        self::assertNull(self::vote('AUS DEM ARCHIV'));
    }

    public function testTheVoteReadsTheInjectedLists(): void
    {
        $stopWords = new class implements StopWordsInterface {
            public function wordsOf(FeedLanguage $language): array
            {
                return FeedLanguage::German === $language ? ['zz'] : ['yy'];
            }
        };

        self::assertSame(FeedLanguage::German, new StopWordVote($stopWords)->languageOf('zz zz yy'));
    }

    private static function vote(string $text): ?FeedLanguage
    {
        return new StopWordVote(LanguageWiring::stopWords())->languageOf($text);
    }
}
