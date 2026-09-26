<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Service\Catalog\CatalogFaviconFetcherInterface;
use App\Service\Catalog\Exception\FaviconUnavailableException;
use App\Service\Mail\Digest\Exception\ImageProcessingException;
use Psr\Log\LoggerInterface;

/**
 * Fetches every thumbnail and favicon a rendered page references, resizes each,
 * and returns them as CID parts keyed by source URL. A distinct URL is fetched
 * once and reused; any fetch or resize failure drops that image, not the mail.
 */
final readonly class DigestImageEmbedder implements DigestImageEmbedderInterface
{
    private const int THUMBNAIL_WIDTH = 176;
    private const int THUMBNAIL_HEIGHT = 132;
    private const int FAVICON_SIZE = 32;

    public function __construct(
        private CatalogFaviconFetcherInterface $downloader,
        private DigestImageResizerInterface $resizer,
        private LoggerInterface $logger,
    ) {
    }

    public function embed(DigestPage $page): DigestImageSet
    {
        $images = [];
        $cidByUrl = [];

        foreach ($this->requests($page) as $url => $kind) {
            try {
                $image = $this->embedOne($url, $kind);
            } catch (FaviconUnavailableException | ImageProcessingException $e) {
                $this->logger->debug('Digest image skipped: {url}', ['url' => $url, 'exception' => $e]);
                continue;
            }

            $cidByUrl[$url] = $image->cid;
            $images[] = $image;
        }

        return new DigestImageSet($images, $cidByUrl);
    }

    /**
     * The first sighting of a URL decides its kind.
     *
     * @return array<string, DigestImageKind>
     */
    private function requests(DigestPage $page): array
    {
        $requests = [];

        foreach ($page->groups as $group) {
            foreach ($group->cards as $card) {
                if ($card->faviconUrl !== null) {
                    $requests[$card->faviconUrl] ??= DigestImageKind::Favicon;
                }
                if ($card->imageUrl !== null) {
                    $requests[$card->imageUrl] ??= DigestImageKind::Thumbnail;
                }
            }
        }

        return $requests;
    }

    /**
     * @throws FaviconUnavailableException
     * @throws ImageProcessingException
     */
    private function embedOne(string $url, DigestImageKind $kind): EmbeddedImage
    {
        return new EmbeddedImage(
            'img' . substr(hash('xxh128', $url), 0, 16),
            $this->resized($this->downloader->download($url)->bytes, $kind),
            $kind->contentType(),
        );
    }

    /**
     * @throws ImageProcessingException
     */
    private function resized(string $sourceBytes, DigestImageKind $kind): string
    {
        return match ($kind) {
            DigestImageKind::Favicon => $this->resizer->containPng(
                $sourceBytes,
                self::FAVICON_SIZE,
                self::FAVICON_SIZE,
            ),
            DigestImageKind::Thumbnail => $this->resizer->coverJpeg(
                $sourceBytes,
                self::THUMBNAIL_WIDTH,
                self::THUMBNAIL_HEIGHT,
            ),
        };
    }
}
