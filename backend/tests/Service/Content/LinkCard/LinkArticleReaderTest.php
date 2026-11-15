<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard;

use App\Entity\LinkCardContent;
use App\Entity\LinkLanguage;
use App\Entity\SharedLink;
use App\Enum\FeedLanguage;
use App\Enum\LanguageSource;
use App\Repository\SharedLinkRepository;
use App\Service\Content\LinkCard\LinkArticleReader;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Service\Content\Reader\Model\ExtractionFailure;
use App\Service\Content\Reader\Model\ExtractionResultModel;
use App\Service\Content\Reader\Model\PagePreviewModel;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\PreviewLanguage;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeArticleExtractor;
use App\Tests\Support\PostEnrichers;

final class LinkArticleReaderTest extends DbTestCase
{
    private const string URL = 'https://example.com/a';

    private FakeArticleExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new FakeArticleExtractor();
    }

    public function testAnOkExtractionRefreshesTheRow(): void
    {
        $this->storeRow(null);
        $this->extractor->willReturn(self::ok(self::preview('Fresh', null)));

        $result = $this->reader()->extract(self::URL);

        self::assertTrue($result->ok);
        self::assertEquals(
            new LinkCardContent(self::URL, 'Fresh', 'Now said', 'https://example.com/i.jpg', 'Example'),
            $this->storedRow()->preview(),
        );
    }

    public function testAPageWithoutAnImageKeepsTheStoredOne(): void
    {
        $this->sharedLinks()->insertMissing([new NewSharedLinkModel(
            (string) (new UrlNormalizer())->hash(self::URL),
            new LinkCardContent(self::URL, 'First', 'Said first', 'https://cdn.example/thumb.jpg', 'example.com'),
            null,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        )]);
        $this->extractor->willReturn(self::ok(new PagePreviewModel(self::URL, 'Fresh', null, null, 'Example', null)));

        $this->reader()->extract(self::URL);

        self::assertEquals(
            new LinkCardContent(self::URL, 'Fresh', 'Said first', 'https://cdn.example/thumb.jpg', 'Example'),
            $this->storedRow()->preview(),
        );
    }

    public function testATrackedAddressRefreshesItsRow(): void
    {
        $this->storeRow(null);
        $this->extractor->willReturn(self::ok(self::preview('Fresh', null)));

        $this->reader()->extract(self::URL . '?utm_source=share');

        self::assertSame('Fresh', $this->storedRow()->preview()->title);
    }

    public function testAnUnsetLanguageIsFilledFromTheStatedTag(): void
    {
        $this->storeRow(null);
        $this->extractor->willReturn(self::ok(self::preview('Fresh', FeedLanguage::French)));

        $this->reader()->extract(self::URL);

        self::assertSame(FeedLanguage::French, $this->storedRow()->getLanguage());
        self::assertSame(LanguageSource::Stated, $this->storedRow()->getLanguageSource());
    }

    public function testAStatedTagReplacesAGuessedLanguage(): void
    {
        $this->storeRow(LinkLanguage::guessed(FeedLanguage::French));
        $this->extractor->willReturn(self::ok(self::preview('Fresh', FeedLanguage::English)));

        $this->reader()->extract(self::URL);

        self::assertSame(FeedLanguage::English, $this->storedRow()->getLanguage());
        self::assertSame(LanguageSource::Stated, $this->storedRow()->getLanguageSource());
        self::assertSame('Fresh', $this->storedRow()->preview()->title);
    }

    public function testAStatedLanguageIsKept(): void
    {
        $this->storeRow(LinkLanguage::stated(FeedLanguage::German));
        $this->extractor->willReturn(self::ok(self::preview('Fresh', FeedLanguage::English)));

        $this->reader()->extract(self::URL);

        self::assertSame(FeedLanguage::German, $this->storedRow()->getLanguage());
    }

    public function testAGuessedLanguageIsKeptWhenThePageStatesNone(): void
    {
        $this->storeRow(LinkLanguage::guessed(FeedLanguage::German));
        $this->extractor->willReturn(self::ok(self::preview('Fresh', null)));

        $this->reader()->extract(self::URL);

        self::assertSame(FeedLanguage::German, $this->storedRow()->getLanguage());
        self::assertSame(LanguageSource::Guessed, $this->storedRow()->getLanguageSource());
    }

    public function testAFailedExtractionChangesNothing(): void
    {
        $this->storeRow(null);
        $failed = ExtractionResultModel::failed(self::URL, ExtractionFailure::Fetch, 'HTTP 403');
        $this->extractor->willReturn($failed);

        self::assertSame($failed, $this->reader()->extract(self::URL));
        self::assertSame('First', $this->storedRow()->preview()->title);
        self::assertNull($this->storedRow()->getLanguage());
    }

    public function testAnOkExtractionWithoutAStorablePreviewChangesNothing(): void
    {
        $this->storeRow(null);
        $this->extractor->willReturn(self::ok(self::preview('  ', FeedLanguage::French)));

        $this->reader()->extract(self::URL);

        self::assertSame('First', $this->storedRow()->preview()->title);
        self::assertNull($this->storedRow()->getLanguage());
    }

    public function testAnOkExtractionWithoutAPreviewChangesNothing(): void
    {
        $this->storeRow(null);
        $this->extractor->willReturn(
            ExtractionResultModel::ok(self::URL, 'Extracted', null, 'Example', '<p>B</p>', null),
        );

        self::assertTrue($this->reader()->extract(self::URL)->ok);
        self::assertSame('First', $this->storedRow()->preview()->title);
    }

    public function testAUrlWithoutARowCreatesNone(): void
    {
        $this->extractor->willReturn(self::ok(self::preview('Fresh', FeedLanguage::French)));

        $result = $this->reader()->extract(self::URL);

        self::assertTrue($result->ok);
        self::assertEquals(0, $this->countRows());
    }

    public function testTheRowsUrlIsKept(): void
    {
        $this->storeRow(null);
        $preview = new PagePreviewModel('https://landing.example/b', 'Fresh', null, null, 'Landing', null);
        $this->extractor->willReturn(self::ok($preview));

        $this->reader()->extract(self::URL);

        self::assertSame(self::URL, $this->storedRow()->preview()->url);
        self::assertSame('Landing', $this->storedRow()->preview()->siteName);
    }

    private static function ok(PagePreviewModel $preview): ExtractionResultModel
    {
        return ExtractionResultModel::ok(
            url: self::URL,
            title: 'Extracted',
            byline: null,
            siteName: 'Example',
            contentHtml: '<p>Body</p>',
            excerpt: null,
            preview: $preview,
        );
    }

    private static function preview(string $title, ?FeedLanguage $language): PagePreviewModel
    {
        return new PagePreviewModel(self::URL, $title, 'Now said', 'https://example.com/i.jpg', 'Example', $language);
    }

    private function storeRow(?LinkLanguage $language): void
    {
        $this->sharedLinks()->insertMissing([new NewSharedLinkModel(
            (string) (new UrlNormalizer())->hash(self::URL),
            new LinkCardContent(self::URL, 'First', null, null, 'example.com'),
            $language,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        )]);
    }

    private function storedRow(): SharedLink
    {
        $this->entityManager->clear();
        $row = $this->sharedLinks()->findOneByUrlHash((string) (new UrlNormalizer())->hash(self::URL));
        self::assertInstanceOf(SharedLink::class, $row);

        return $row;
    }

    private function countRows(): mixed
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shared_link');
    }

    private function sharedLinks(): SharedLinkRepository
    {
        return PostEnrichers::sharedLinks($this->entityManager);
    }

    private function reader(): LinkArticleReader
    {
        return new LinkArticleReader(
            $this->extractor,
            PostEnrichers::sharedLinkWriter($this->entityManager),
            new PreviewLanguage(new LanguageGuess(new LanguageDetectionFactory()->createForFeedLanguages())),
            $this->entityManager,
        );
    }
}
