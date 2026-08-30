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

        foreach ($this->requests($page) as $url => $isFavicon) {
            $embedded = $this->tryEmbed($url, $isFavicon);
            if ($embedded === null) {
                continue;
            }

            $cidByUrl[$url] = $embedded->cid;
            $images[] = $embedded;
        }

        return new DigestImageSet($images, $cidByUrl);
    }

    /**
     * Distinct source URLs, each flagged favicon-or-thumbnail; first sighting wins.
     *
     * @return array<string, bool>
     */
    private function requests(DigestPage $page): array
    {
        $requests = [];

        foreach ($page->groups as $group) {
            foreach ($group->cards as $card) {
                if ($card->faviconUrl !== null) {
                    $requests[$card->faviconUrl] ??= true;
                }
                if ($card->imageUrl !== null) {
                    $requests[$card->imageUrl] ??= false;
                }
            }
        }

        return $requests;
    }

    private function tryEmbed(string $url, bool $isFavicon): ?EmbeddedImage
    {
        try {
            $raw = $this->downloader->download($url)->bytes;
            $bytes = $isFavicon
                ? $this->resizer->containPng($raw, self::FAVICON_SIZE, self::FAVICON_SIZE)
                : $this->resizer->coverJpeg($raw, self::THUMBNAIL_WIDTH, self::THUMBNAIL_HEIGHT);

            return new EmbeddedImage(
                'img' . substr(hash('xxh128', $url), 0, 16),
                $bytes,
                $isFavicon ? 'image/png' : 'image/jpeg',
            );
        } catch (FaviconUnavailableException | ImageProcessingException $e) {
            $this->logger->debug('Digest image skipped: {url}', ['url' => $url, 'exception' => $e]);

            return null;
        }
    }
}
