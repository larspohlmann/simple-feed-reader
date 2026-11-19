<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\CardLanguage;
use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use App\Service\Curation\Trending\Pass\SharedArticles;
use App\Service\Curation\Trending\Support\HeadlineBody;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Tests\Support\StubbedDetector;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharedArticlesTest extends TestCase
{
    private const string LONG_FRENCH = 'Le conseil municipal a voté mardi pour garder la vieille bibliothèque ouverte'
        . ' une année de plus, après que des centaines d’habitants ont écrit pour demander de ne pas la fermer.';

    public function testAStoryCardIsReadInItsOwnLanguage(): void
    {
        $article = (new SharedArticles(TrendingWiring::cardLanguage()))->of(self::postSharing(
            1,
            FeedLanguage::English,
            'https://outlet.example/library',
        ));

        self::assertNotNull($article);
        self::assertSame('Bibliothèque', $article->title);
        self::assertSame(self::LONG_FRENCH, $article->body);
        self::assertSame(FeedLanguage::French, $article->language);
    }

    public function testACardSharedAgainInOnePostLanguageIsTheSameArticle(): void
    {
        $articles = new SharedArticles(TrendingWiring::cardLanguage());
        $url = 'https://outlet.example/library';

        $first = $articles->of(self::postSharing(1, FeedLanguage::German, $url));

        self::assertSame($first, $articles->of(self::postSharing(2, FeedLanguage::German, $url)));
        self::assertNotSame($first, $articles->of(self::postSharing(3, FeedLanguage::Spanish, $url)));
    }

    public function testTwoLinksInOnePostLanguageAreTwoArticles(): void
    {
        $articles = new SharedArticles(TrendingWiring::cardLanguage());

        $first = $articles->of(self::postSharing(1, FeedLanguage::German, 'https://outlet.example/library'));

        self::assertNotSame(
            $first,
            $articles->of(self::postSharing(2, FeedLanguage::German, 'https://outlet.example/museum')),
        );
        $storedA = self::postSharing(3, FeedLanguage::German, 'https://outlet.example/a', FeedLanguage::French);
        $storedB = self::postSharing(4, FeedLanguage::German, 'https://outlet.example/b', FeedLanguage::French);
        self::assertNotSame($articles->of($storedA), $articles->of($storedB));
    }

    public function testAPostWithoutAStoryCardSharesNoArticle(): void
    {
        self::assertNull((new SharedArticles(TrendingWiring::cardLanguage()))->of(
            self::postSharing(1, FeedLanguage::German, 'https://outlet.example/'),
        ));
    }

    public function testAStoredLanguageIsUsedWithoutGuessing(): void
    {
        $detector = StubbedDetector::saying('de');
        $articles = new SharedArticles(new CardLanguage(new LanguageGuess($detector)));
        $url = 'https://outlet.example/library';

        $article = $articles->of(self::postSharing(1, FeedLanguage::English, $url, FeedLanguage::Spanish));

        self::assertSame(FeedLanguage::Spanish, $article?->language);
        self::assertSame([], $detector->seenTexts());
    }

    public function testARowWithAStoredLanguageIsOneArticleWhateverThePostLanguage(): void
    {
        $articles = new SharedArticles(TrendingWiring::cardLanguage());
        $url = 'https://outlet.example/library';

        $first = $articles->of(self::postSharing(1, FeedLanguage::German, $url, FeedLanguage::French));

        self::assertSame(
            $first,
            $articles->of(self::postSharing(2, FeedLanguage::Spanish, $url, FeedLanguage::French)),
        );
    }

    public function testAnUnsetLanguageFallsBackToTheCardLanguage(): void
    {
        $article = (new SharedArticles(TrendingWiring::cardLanguage()))->of(
            self::postSharing(1, FeedLanguage::English, 'https://outlet.example/library'),
        );

        self::assertSame(FeedLanguage::French, $article?->language);
    }

    #[DataProvider('fallbackCases')]
    public function testTheFallbackEqualsDevelopsRule(string $description, ?FeedLanguage $postLanguage): void
    {
        $entry = self::postSharing(1, $postLanguage, 'https://outlet.example/library', null, $description);

        $article = (new SharedArticles(TrendingWiring::cardLanguage()))->of($entry);

        $body = HeadlineBody::body($description, HeadlineBody::BODY_LENGTH);

        self::assertSame(
            TrendingWiring::cardLanguage()->of('Bibliothèque' . "\n" . $body, $postLanguage),
            $article?->language,
        );
    }

    /** @return iterable<string, array{string, ?FeedLanguage}> */
    public static function fallbackCases(): iterable
    {
        yield 'long, post language set' => [self::LONG_FRENCH, FeedLanguage::English];
        yield 'long, post language unset' => [self::LONG_FRENCH, null];
        yield 'short, post language set' => ['Court texte', FeedLanguage::German];
        yield 'short, post language unset' => ['Court texte', null];
    }

    private static function postSharing(
        int $id,
        ?FeedLanguage $language,
        string $cardUrl,
        ?FeedLanguage $cardLanguage = null,
        string $description = self::LONG_FRENCH,
    ): WindowEntryModel {
        $card = new WindowLinkCardModel(
            crc32($cardUrl),
            hash('sha256', $cardUrl),
            $cardUrl,
            'Bibliothèque',
            $description,
            'Outlet',
            $cardLanguage,
        );

        return new WindowEntryModel(
            $id,
            $id * 10,
            $language,
            'Lesenswert',
            false,
            null,
            new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC')),
            new EntryLinksModel(null, null, $card),
        );
    }
}
