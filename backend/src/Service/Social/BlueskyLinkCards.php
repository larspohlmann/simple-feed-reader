<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Exception\ImageProcessingException;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\ImageResizer\ImageResizerInterface;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use Psr\Log\LoggerInterface;

/** The external embed a Bluesky link post carries, read from the page; a thumbnail that cannot be made is left out. */
final readonly class BlueskyLinkCards
{
    private const string EXTERNAL_EMBED = 'app.bsky.embed.external';
    private const string UPLOAD_BLOB = '/xrpc/com.atproto.repo.uploadBlob';
    private const string THUMBNAIL_TYPE = 'image/jpeg';
    private const int THUMBNAIL_WIDTH = 1200;
    private const int THUMBNAIL_HEIGHT = 630;

    public function __construct(
        private PageCardReaderInterface $pageCards,
        private GuardedImageDownloaderInterface $downloader,
        private ImageResizerInterface $resizer,
        private BlueskySessions $sessions,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function cardOf(SocialAccount $account, string $url): array
    {
        $card = $this->pageCardOf($url);

        return ['$type' => self::EXTERNAL_EMBED, 'external' => [
            'uri' => $url,
            'title' => $card->title,
            'description' => $card->description ?? '',
            ...$this->thumbnailOf($account, $card->imageUrl),
        ]];
    }

    private function pageCardOf(string $url): LinkCardContent
    {
        try {
            return $this->pageCards->read($url)->card ?? self::hostCardOf($url);
        } catch (PageFetchException $exception) {
            $this->logger->debug('The link card goes out without the page\'s own card.', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return self::hostCardOf($url);
        }
    }

    private static function hostCardOf(string $url): LinkCardContent
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $title = \is_string($host) && '' !== $host ? $host : $url;

        return new LinkCardContent($url, $title, null, null, $title);
    }

    /** @return array{thumb?: array<mixed>} */
    private function thumbnailOf(SocialAccount $account, ?string $imageUrl): array
    {
        if (null === $imageUrl) {
            return [];
        }

        try {
            return $this->uploaded($account, $imageUrl);
        } catch (
            GuardedImageUnavailableException
            | ImageProcessingException
            | SocialNetworkUnreachableException
            | SocialPostNotFoundException
            | SocialPostRefusedException $exception
        ) {
            $this->logger->debug('The link card goes out without a thumbnail.', [
                'image' => $imageUrl,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /** @return array{thumb?: array<mixed>} */
    private function uploaded(SocialAccount $account, string $imageUrl): array
    {
        $thumbnail = $this->resizer->coverJpeg(
            $this->downloader->download($imageUrl)->bytes,
            self::THUMBNAIL_WIDTH,
            self::THUMBNAIL_HEIGHT,
        );
        $answer = $this->sessions->uploadAs($account, self::UPLOAD_BLOB, $thumbnail, self::THUMBNAIL_TYPE);
        $blob = $answer->json()['blob'] ?? null;

        return \is_array($blob) ? ['thumb' => $blob] : [];
    }
}
