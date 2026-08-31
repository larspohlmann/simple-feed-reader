<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\PageMediaScanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageMediaScannerWiringTest extends KernelTestCase
{
    public function testTheTaggedSourcesAreCollected(): void
    {
        self::bootKernel();
        $scanner = self::getContainer()->get(PageMediaScanner::class);
        self::assertInstanceOf(PageMediaScanner::class, $scanner);

        $html = '<html><body><iframe src="https://www.youtube.com/embed/M1j_uRqKMKI"></iframe></body></html>';
        $media = $scanner->scan($html, 'https://example.test/article');

        self::assertFalse($media->isEmpty());
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $media->candidates[0]->url);
    }
}
