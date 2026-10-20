<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\UrlArticleReader;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\FakeArticleExtractor;
use PHPUnit\Framework\TestCase;

final class UrlArticleReaderTest extends TestCase
{
    public function testExtractsTheUrlWithoutHints(): void
    {
        $extractor = new FakeArticleExtractor();
        $extracted = ExtractionResultModel::ok('https://example.com/a', 'A', null, null, '<p>Body</p>', null);
        $extractor->willReturn($extracted);

        $result = (new UrlArticleReader($extractor))->read('https://example.com/a');

        self::assertSame($extracted, $result);
        self::assertSame(['https://example.com/a'], $extractor->calls);
        self::assertNull($extractor->hints[0]->title);
    }
}
