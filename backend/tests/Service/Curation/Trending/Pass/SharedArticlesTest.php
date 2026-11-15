<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use App\Service\Curation\Trending\Pass\SharedArticles;
use App\Tests\Support\TrendingWiring;
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

    public function testAPostWithoutAStoryCardSharesNoArticle(): void
    {
        self::assertNull((new SharedArticles(TrendingWiring::cardLanguage()))->of(
            self::postSharing(1, FeedLanguage::German, 'https://outlet.example/'),
        ));
    }

    private static function postSharing(int $id, FeedLanguage $language, string $cardUrl): WindowEntryModel
    {
        $card = new WindowLinkCardModel($cardUrl, 'Bibliothèque', self::LONG_FRENCH, 'Outlet');

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
