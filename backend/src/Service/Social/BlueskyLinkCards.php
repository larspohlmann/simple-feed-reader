<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Exception\ImageProcessingException;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\ImageResizer\ImageResizerInterface;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SharedLinkModel;
use Psr\Log\LoggerInterface;

/** The external embed a Bluesky link post carries; a thumbnail that cannot be made is left out. */
final readonly class BlueskyLinkCards
{
    private const string EXTERNAL_EMBED = 'app.bsky.embed.external';
    private const string UPLOAD_BLOB = '/xrpc/com.atproto.repo.uploadBlob';
    private const string THUMBNAIL_TYPE = 'image/jpeg';
    private const int THUMBNAIL_WIDTH = 1200;
    private const int THUMBNAIL_HEIGHT = 630;

    public function __construct(
        private GuardedImageDownloaderInterface $downloader,
        private ImageResizerInterface $resizer,
        private BlueskySessions $sessions,
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> */
    public function cardOf(SocialAccount $account, SharedLinkModel $link): array
    {
        return ['$type' => self::EXTERNAL_EMBED, 'external' => [
            'uri' => $link->url,
            'title' => $link->title,
            'description' => $link->description ?? '',
            ...$this->thumbnailOf($account, $link),
        ]];
    }

    /** @return array{thumb?: array<mixed>} */
    private function thumbnailOf(SocialAccount $account, SharedLinkModel $link): array
    {
        if (null === $link->imageUrl) {
            return [];
        }

        try {
            return $this->uploaded($account, $link->imageUrl);
        } catch (
            GuardedImageUnavailableException
            | ImageProcessingException
            | SocialNetworkUnreachableException
            | SocialPostNotFoundException
            | SocialPostRefusedException $exception
        ) {
            $this->logger->debug('The link card goes out without a thumbnail.', [
                'image' => $link->imageUrl,
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
