<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest\DigestImageEmbedder;

use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\Model\DownloadedImageModel;
use App\Service\Mail\Digest\DigestImageEmbedder\DigestImageEmbedder;
use App\Service\Mail\Digest\DigestImageResizer\DigestImageResizerInterface;
use App\Service\Mail\Digest\Exception\ImageProcessingException;
use App\Service\Mail\Digest\Model\DigestEntryModel;
use App\Service\Mail\Digest\Model\DigestImageSetModel;
use App\Service\Mail\Digest\Model\DigestPageGroupModel;
use App\Service\Mail\Digest\Model\DigestPageModel;
use App\Service\Mail\Digest\Model\EmbeddedImageModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DigestImageEmbedderTest extends TestCase
{
    private function card(?string $imageUrl, ?string $faviconUrl): DigestEntryModel
    {
        return new DigestEntryModel('T', 'Feed', '', 'https://example.com/e', null, $imageUrl, $faviconUrl);
    }

    private function page(DigestEntryModel ...$cards): DigestPageModel
    {
        $list = array_values($cards);

        return new DigestPageModel([new DigestPageGroupModel('t', \count($list), $list, 0, '')], \count($list));
    }

    private function embeddedFor(DigestImageSetModel $set, string $url): EmbeddedImageModel
    {
        $cid = $set->cidFor($url);
        self::assertNotNull($cid, "No image was embedded for {$url}.");

        foreach ($set->images as $image) {
            if ($image->cid === $cid) {
                return $image;
            }
        }

        self::fail("The image set carries a cid for {$url} but no matching EmbeddedImageModel.");
    }

    public function testDedupesASharedFaviconToOneEmbed(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturnCallback(
            static fn (string $url): DownloadedImageModel => new DownloadedImageModel($url, 'RAW', 'image/png'),
        );
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');
        $resizer->method('containPng')->willReturn('PNG');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))->embed($this->page(
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
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturnCallback(
            static function (string $url): DownloadedImageModel {
                if ($url === 'https://cdn/bad.jpg') {
                    throw new GuardedImageUnavailableException('boom');
                }

                return new DownloadedImageModel($url, 'RAW', 'image/png');
            },
        );
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');
        $resizer->method('containPng')->willReturn('PNG');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))->embed($this->page(
            $this->card('https://cdn/bad.jpg', 'https://site/favicon.ico'),
        ));

        self::assertNull($set->cidFor('https://cdn/bad.jpg'));
        self::assertNotNull($set->cidFor('https://site/favicon.ico'));
    }

    /**
     * The failing favicon is requested first (favicons are inserted before images
     * for the same card); a broken early-exit (`break` instead of `continue`)
     * would abandon the loop and drop the image that follows it too.
     */
    public function testAFetchFailureDoesNotStopLaterImagesInTheLoop(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturnCallback(
            static function (string $url): DownloadedImageModel {
                if ($url === 'https://site/bad.ico') {
                    throw new GuardedImageUnavailableException('boom');
                }

                return new DownloadedImageModel($url, 'RAW', 'image/png');
            },
        );
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');
        $resizer->method('containPng')->willReturn('PNG');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))->embed($this->page(
            $this->card('https://cdn/1.jpg', 'https://site/bad.ico'),
        ));

        self::assertNull($set->cidFor('https://site/bad.ico'));
        self::assertNotNull($set->cidFor('https://cdn/1.jpg'));
    }

    public function testAUrlSeenOnlyAsAFaviconIsResizedWithContainPngToPng(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturn(new DownloadedImageModel('u', 'RAW', 'image/png'));
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPEGBYTES');
        $resizer->method('containPng')->willReturn('PNGBYTES');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))
            ->embed($this->page($this->card(null, 'https://site/favicon.ico')));

        $image = $this->embeddedFor($set, 'https://site/favicon.ico');
        self::assertSame('image/png', $image->contentType);
        self::assertSame('PNGBYTES', $image->bytes);
    }

    public function testAUrlSeenOnlyAsAnImageIsResizedWithCoverJpegToJpeg(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturn(new DownloadedImageModel('u', 'RAW', 'image/jpeg'));
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPEGBYTES');
        $resizer->method('containPng')->willReturn('PNGBYTES');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))
            ->embed($this->page($this->card('https://cdn/1.jpg', null)));

        $image = $this->embeddedFor($set, 'https://cdn/1.jpg');
        self::assertSame('image/jpeg', $image->contentType);
        self::assertSame('JPEGBYTES', $image->bytes);
    }

    public function testAUrlFirstSeenAsAThumbnailStaysAThumbnailWhenLaterUsedAsAFavicon(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturn(new DownloadedImageModel('u', 'RAW', 'image/jpeg'));
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPEGBYTES');
        $resizer->method('containPng')->willReturn('PNGBYTES');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))->embed($this->page(
            $this->card('https://shared/pic', null),
            $this->card(null, 'https://shared/pic'),
        ));

        $image = $this->embeddedFor($set, 'https://shared/pic');
        self::assertSame('image/jpeg', $image->contentType, 'First sighting (as a thumbnail) must win.');
    }

    public function testAUrlFirstSeenAsAFaviconStaysAFaviconWhenLaterUsedAsAThumbnail(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturn(new DownloadedImageModel('u', 'RAW', 'image/png'));
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPEGBYTES');
        $resizer->method('containPng')->willReturn('PNGBYTES');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))->embed($this->page(
            $this->card(null, 'https://shared/pic'),
            $this->card('https://shared/pic', null),
        ));

        $image = $this->embeddedFor($set, 'https://shared/pic');
        self::assertSame('image/png', $image->contentType, 'First sighting (as a favicon) must win.');
    }

    public function testCidIsDerivedFromASixteenCharacterHashOfTheSourceUrl(): void
    {
        $url = 'https://cdn/1.jpg';
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturn(new DownloadedImageModel($url, 'RAW', 'image/jpeg'));
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('JPG');

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))
            ->embed($this->page($this->card($url, null)));

        self::assertSame('img' . substr(hash('xxh128', $url), 0, 16), $set->cidFor($url));
    }

    public function testAnImageProcessingExceptionAlsoDropsOnlyThatImage(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willReturn(new DownloadedImageModel('u', 'RAW', 'image/jpeg'));
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $resizer->method('coverJpeg')->willThrowException(new ImageProcessingException('broken image'));

        $set = (new DigestImageEmbedder($downloader, $resizer, new NullLogger()))
            ->embed($this->page($this->card('https://cdn/broken.jpg', null)));

        self::assertNull($set->cidFor('https://cdn/broken.jpg'));
        self::assertCount(0, $set->images);
    }

    public function testAFailureIsLoggedWithTheOffendingUrl(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willThrowException(new GuardedImageUnavailableException('boom'));
        $resizer = $this->createStub(DigestImageResizerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => ($context['url'] ?? null) === 'https://cdn/broken.jpg'),
        );

        (new DigestImageEmbedder($downloader, $resizer, $logger))
            ->embed($this->page($this->card('https://cdn/broken.jpg', null)));
    }
}
