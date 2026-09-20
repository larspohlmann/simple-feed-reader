<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\RawPage;
use App\Service\Reader\Media\Source\ScannedPage;
use PHPUnit\Framework\TestCase;

final class ScannedPageTest extends TestCase
{
    public function testFromReadsNoPosterWhenThePageFailedToParse(): void
    {
        $page = ScannedPage::from(RawPage::parse('', 'https://example.test/article'));

        self::assertNull($page->posterUrl);
        self::assertSame('https://example.test/article', $page->url);
    }

    public function testFromReadsTheOgImageAsThePoster(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:image" content="https://example.test/poster.jpg">
            </head><body></body></html>
            HTML;

        $page = ScannedPage::from(RawPage::parse($html, 'https://example.test/article'));

        self::assertSame('https://example.test/poster.jpg', $page->posterUrl);
    }
}
