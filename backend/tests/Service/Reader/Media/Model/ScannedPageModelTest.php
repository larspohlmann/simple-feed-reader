<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Model;

use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\Model\ScannedPageModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScannedPageModelTest extends TestCase
{
    private const string PAGE_URL = 'https://example.test/article';

    /** @return iterable<string, array{string, ?string}> */
    public static function ogImageProvider(): iterable
    {
        yield 'https og:image' => [
            '<meta property="og:image" content="https://example.test/poster.jpg">',
            'https://example.test/poster.jpg',
        ];
        yield 'http og:image' => ['<meta property="og:image" content="http://example.test/poster.jpg">', null];
        yield 'protocol-relative og:image' => ['<meta property="og:image" content="//example.test/poster.jpg">', null];
        yield 'no og:image' => ['<title>No poster here</title>', null];
    }

    #[DataProvider('ogImageProvider')]
    public function testFromReadsOnlyAnHttpsOgImageAsThePoster(string $head, ?string $expectedPoster): void
    {
        $html = '<html><head>' . $head . '</head><body></body></html>';

        $page = ScannedPageModel::from(RawPageModel::parse($html, self::PAGE_URL));

        self::assertSame($expectedPoster, $page->posterUrl);
        self::assertSame(self::PAGE_URL, $page->url);
    }
}
