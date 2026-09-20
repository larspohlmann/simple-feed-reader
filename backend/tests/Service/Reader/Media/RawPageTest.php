<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Media\RawPage;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class RawPageTest extends TestCase
{
    public function testKeepsTheRawMarkupAndUrl(): void
    {
        $html = '<html><body><p>Prose.</p></body></html>';

        $page = RawPage::parse($html, 'https://x.test/a');

        self::assertSame($html, $page->html);
        self::assertSame('https://x.test/a', $page->url);
    }

    public function testParsesTheMarkupIntoOneSharedDocument(): void
    {
        $page = RawPage::parse('<html><body><p>Prose.</p></body></html>', 'https://x.test/a');

        self::assertInstanceOf(HTMLDocument::class, $page->document);
    }

    public function testBlocksAnchorToTheProseThatPrecedesAMediaElement(): void
    {
        $html = '<html><body><p>A paragraph long enough to survive the cleaners.</p>'
            . '<video src="https://x.test/v.mp4"></video></body></html>';

        $page = RawPage::parse($html, 'https://x.test/a');

        $video = $page->document?->querySelector('video');
        self::assertNotNull($video);
        self::assertSame('A paragraph long enough to survive the cleaners.', $page->blocks->before($video));
    }

    public function testUnparseablePageHasNoDocumentButStillOffersEmptyBlocks(): void
    {
        $page = RawPage::parse('   ', 'https://x.test/a');

        self::assertNull($page->document);
        self::assertInstanceOf(PageTextBlocks::class, $page->blocks);
    }
}
