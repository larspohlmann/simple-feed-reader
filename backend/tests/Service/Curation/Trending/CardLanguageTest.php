<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\CardLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardLanguageTest extends TestCase
{
    private const string LONG_ENGLISH = 'The town council voted on Tuesday to keep the old library open for another'
        . ' year, after hundreds of residents wrote letters asking them not to close it before the new one is built.';
    private const string LONG_FRENCH = 'Le conseil municipal a voté mardi pour garder la vieille bibliothèque ouverte'
        . ' une année de plus, après que des centaines d’habitants ont écrit pour demander de ne pas la fermer.';
    private const string LONG_SPANISH = 'El ayuntamiento votó el martes mantener abierta la vieja biblioteca un año'
        . ' más, después de que cientos de vecinos escribieran cartas pidiendo que no la cerraran todavía.';

    /** @return iterable<string, array{string, ?FeedLanguage, ?FeedLanguage}> */
    public static function cards(): iterable
    {
        yield 'a long English card under a German post' => [
            self::LONG_ENGLISH,
            FeedLanguage::German,
            FeedLanguage::English,
        ];
        yield 'a long French card under an English post' => [
            self::LONG_FRENCH,
            FeedLanguage::English,
            FeedLanguage::French,
        ];
        yield 'a long Spanish card under a German post' => [
            self::LONG_SPANISH,
            FeedLanguage::German,
            FeedLanguage::Spanish,
        ];
        yield 'a long Spanish card under a French post' => [
            self::LONG_SPANISH,
            FeedLanguage::French,
            FeedLanguage::Spanish,
        ];
        yield 'a short English title under a German post' => [
            'Council keeps the library open',
            FeedLanguage::German,
            FeedLanguage::German,
        ];
        yield 'a short French title under a post with no language' => [
            'Le conseil garde la bibliothèque ouverte',
            null,
            FeedLanguage::French,
        ];
        yield 'a French card one short of long under an English post' => [
            mb_substr(self::LONG_FRENCH, 0, 149),
            FeedLanguage::English,
            FeedLanguage::English,
        ];
        yield 'a French card just long enough under an English post' => [
            mb_substr(self::LONG_FRENCH, 0, 150),
            FeedLanguage::English,
            FeedLanguage::French,
        ];
        yield 'a text with no letters under no language' => ['2031 – 42 %', null, null];
        yield 'a text with no letters under a Spanish post' => [
            str_repeat('2031 – 42 % ', 20),
            FeedLanguage::Spanish,
            FeedLanguage::Spanish,
        ];
    }

    #[DataProvider('cards')]
    public function testALongCardReadsInItsOwnLanguageAShortOneInThePosts(
        string $text,
        ?FeedLanguage $postLanguage,
        ?FeedLanguage $cardLanguage,
    ): void {
        $languages = new CardLanguage(new LanguageGuess((new LanguageDetectionFactory())->createForFeedLanguages()));

        self::assertSame($cardLanguage, $languages->resolveLanguage($text, $postLanguage));
    }
}
