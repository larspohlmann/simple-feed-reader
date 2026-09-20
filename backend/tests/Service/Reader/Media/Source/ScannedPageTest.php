<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\RawPage;
use App\Service\Reader\Media\Source\ScannedPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScannedPageTest extends TestCase
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

        $page = ScannedPage::from(RawPage::parse($html, self::PAGE_URL));

        self::assertSame($expectedPoster, $page->posterUrl);
        self::assertSame(self::PAGE_URL, $page->url);
    }
}
