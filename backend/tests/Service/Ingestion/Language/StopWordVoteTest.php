<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\StopWords\StopWordsInterface;
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
        self::assertNull(self::vote('Rendez à Berlin'));
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

    public function testAWordInAccentedCapitalsDoesNotVote(): void
    {
        self::assertSame(FeedLanguage::English, self::vote('ÜBER the house'));
        self::assertSame(FeedLanguage::English, self::vote("U\u{0308}BER the house"));
    }

    public function testAShoutedTextHasNoVote(): void
    {
        self::assertNull(self::vote('AUS DEM ARCHIV'));
    }

    public function testAnEnglishLinkSlugDoesNotGiveAJapanesePostAVote(): void
    {
        self::assertNull(self::vote('今日のニュース https://www.example.com/news/the-day-of-the-vote'));
    }

    public function testALinkWithoutSchemeDoesNotVote(): void
    {
        self::assertSame(FeedLanguage::English, self::vote('Recover from failures laravel-news.com/laravel-ai-sdk'));
        self::assertSame(FeedLanguage::English, self::vote('A brain teaser from Slate. slate.trib.al/Vond4Dm'));
    }

    public function testLinksMentionsAndHashtagsDoNotVote(): void
    {
        self::assertNull(self::vote('Lesenswert https://example.com/the-best-of-the-year'));
        self::assertNull(self::vote('Danke @the_best_of_the'));
        self::assertNull(self::vote('Damn. #ai #genAI #aiSucks'));
        self::assertSame(FeedLanguage::German, self::vote('Aus dem Archiv https://example.com/the'));
    }

    public function testTheVoteReadsTheInjectedLists(): void
    {
        $stopWords = new class implements StopWordsInterface {
            public function listWords(FeedLanguage $language): array
            {
                return FeedLanguage::German === $language ? ['zz'] : ['yy'];
            }
        };

        self::assertSame(FeedLanguage::German, new StopWordVote($stopWords)->detectLanguage('zz zz yy'));
    }

    private static function vote(string $text): ?FeedLanguage
    {
        return new StopWordVote(LanguageWiring::stopWords())->detectLanguage($text);
    }
}
