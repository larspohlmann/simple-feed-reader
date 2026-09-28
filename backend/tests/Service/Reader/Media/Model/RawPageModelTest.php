<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Model;

use App\Service\Reader\Media\Model\RawPageModel;
use PHPUnit\Framework\TestCase;

final class RawPageModelTest extends TestCase
{
    public function testKeepsTheRawMarkupAndUrl(): void
    {
        $html = '<html><body><p>Prose.</p></body></html>';

        $page = RawPageModel::parse($html, 'https://x.test/a');

        self::assertSame($html, $page->html);
        self::assertSame('https://x.test/a', $page->url);
    }

    public function testBlocksAnchorToTheProseThatPrecedesAMediaElement(): void
    {
        $html = '<html><body><p>A paragraph long enough to survive the cleaners.</p>'
            . '<video src="https://x.test/v.mp4"></video></body></html>';

        $page = RawPageModel::parse($html, 'https://x.test/a');

        $video = $page->document->querySelector('video');
        self::assertNotNull($video);
        self::assertSame('A paragraph long enough to survive the cleaners.', $page->blocks->before($video));
    }

    public function testABlankPageParsesToAnEmptyDocument(): void
    {
        $page = RawPageModel::parse('   ', 'https://x.test/a');

        self::assertNull($page->document->documentElement);
    }
}
