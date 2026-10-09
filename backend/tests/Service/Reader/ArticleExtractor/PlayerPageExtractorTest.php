<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\ArticleExtractor;

use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\ArticleExtractor\PlayerPageExtractor;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use PHPUnit\Framework\TestCase;

final class PlayerPageExtractorTest extends TestCase
{
    public function testAVideoPageIsNeverFetched(): void
    {
        $inner = $this->createMock(ArticleExtractorInterface::class);
        $inner->expects($this->never())->method('extract');

        $result = (new PlayerPageExtractor($inner, new EmbedProviders([new YouTubeEmbedProvider()])))
            ->extract('https://www.youtube.com/watch?v=Xic3faS00Qs');

        self::assertFalse($result->ok);
        self::assertSame(ExtractionFailure::PlayerPage, $result->reason);
    }

    public function testAnyOtherPageIsExtracted(): void
    {
        $extracted = ExtractionResultModel::failed('https://example.com/a', ExtractionFailure::Empty);
        $hints = new EntryHintsModel(title: 'T');
        $inner = $this->createMock(ArticleExtractorInterface::class);
        $inner->expects($this->once())->method('extract')->with('https://example.com/a', $hints)
            ->willReturn($extracted);

        $result = (new PlayerPageExtractor($inner, new EmbedProviders([new YouTubeEmbedProvider()])))
            ->extract('https://example.com/a', $hints);

        self::assertSame($extracted, $result);
    }
}
