<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\LinkCardContent;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Reader\LinkCardArticleReader;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\FakeArticleExtractor;
use PHPUnit\Framework\TestCase;

final class LinkCardArticleReaderTest extends TestCase
{
    public function testExtractsTheCardsPageWithItsTitleAsTheHint(): void
    {
        $extractor = new FakeArticleExtractor();
        $extracted = ExtractionResultModel::ok('https://example.com/a', 'A', null, null, '<p>Body</p>', null);
        $extractor->willReturn($extracted);

        $result = (new LinkCardArticleReader($extractor))->read($this->card('https://example.com/a.jpg'));

        self::assertSame($extracted, $result);
        self::assertSame(['https://example.com/a'], $extractor->calls);
        self::assertSame('The card title', $extractor->hints[0]->title);
    }

    public function testTheCardImageLeads(): void
    {
        $lead = (new LinkCardArticleReader(new FakeArticleExtractor()))->lead($this->card('https://example.com/a.jpg'));

        self::assertEquals(new DeclaredImageModel('https://example.com/a.jpg'), $lead);
    }

    public function testACardWithoutAnImageHasNoLead(): void
    {
        self::assertNull((new LinkCardArticleReader(new FakeArticleExtractor()))->lead($this->card(null)));
    }

    private function card(?string $imageUrl): LinkCardContent
    {
        return new LinkCardContent('https://example.com/a', 'The card title', null, $imageUrl, 'Example');
    }
}
