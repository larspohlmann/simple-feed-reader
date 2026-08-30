<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Catalog\FetchedFavicon;
use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestImageEmbedder;
use App\Service\Mail\Digest\DigestImageResizerInterface;
use App\Service\Mail\Digest\DigestPage;
use App\Service\Mail\Digest\DigestPageGroup;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

final class DigestImageEmbedderTest extends TestCase
{
    private function card(string $imageUrl, string $faviconUrl): DigestEntry
    {
        return new DigestEntry('T', 'Feed', '', 'https://example.com/e', null, $imageUrl, null, null, $faviconUrl);
    }

    private function page(DigestEntry ...$cards): DigestPage
    {
        $list = array_values($cards);

        return new DigestPage([new DigestPageGroup('t', \count($list), $list, 0, '')], \count($list));
    }

    public function testDedupesASharedFaviconToOneEmbed(): void
    {
        $fetcher = $this->createStub(CatalogFaviconFetcherInterface::class);
        $fetcher->method('download')->willReturnCallback(
            static fn (string $url): FetchedFavicon => new FetchedFavicon($url, 'RAW', 'image/png'),
        );
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');
        $resizer->method('containPng')->willReturn('PNG');

        $set = (new DigestImageEmbedder($fetcher, $resizer, new NullLogger()))->embed($this->page(
            $this->card('https://cdn/1.jpg', 'https://site/favicon.ico'),
            $this->card('https://cdn/2.jpg', 'https://site/favicon.ico'),
        ));

        // 2 distinct thumbnails + 1 shared favicon = 3 embeds, not 4.
        self::assertCount(3, $set->images);
        self::assertNotNull($set->cidFor('https://cdn/1.jpg'));
        self::assertSame($set->cidFor('https://site/favicon.ico'), $set->cidFor('https://site/favicon.ico'));
    }

    public function testAFetchFailureDropsThatImageOnly(): void
    {
        $fetcher = $this->createStub(CatalogFaviconFetcherInterface::class);
        $fetcher->method('download')->willReturnCallback(
            static function (string $url): FetchedFavicon {
                if ($url === 'https://cdn/bad.jpg') {
                    throw new FaviconUnavailableException('boom');
                }

                return new FetchedFavicon($url, 'RAW', 'image/png');
            },
        );
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');
        $resizer->method('containPng')->willReturn('PNG');

        $set = (new DigestImageEmbedder($fetcher, $resizer, new NullLogger()))->embed($this->page(
            $this->card('https://cdn/bad.jpg', 'https://site/favicon.ico'),
        ));

        self::assertNull($set->cidFor('https://cdn/bad.jpg'));
        self::assertNotNull($set->cidFor('https://site/favicon.ico'));
    }
}
