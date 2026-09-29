<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;

/**
 * The media the feed declared for one entry. For a scraped URL the feed enumerated, its declared kind, MIME and pixel
 * size win over the reader's guesses; it also holds the poster fallback. No outbound HTTP: all of it is persisted.
 */
final readonly class FeedMediaModel
{
    /**
     * @param list<EntryMedium>     $media
     * @param list<EntryAttachment> $attachments
     */
    private function __construct(
        private array $media,
        private array $attachments,
        private ?string $leadImageUrl,
    ) {
    }

    public static function fromEntry(Entry $entry): self
    {
        return new self($entry->getMedia(), $entry->getAttachments(), $entry->getImageUrl());
    }

    public static function none(): self
    {
        return new self([], [], null);
    }

    /** The still the reader gives a poster-less video: a video medium's own preview, else the lead image. */
    public function posterFallback(): ?string
    {
        foreach ($this->media as $medium) {
            if ($medium->kind === 'video' && $medium->previewImageUrl !== null) {
                return $medium->previewImageUrl;
            }
        }

        return $this->leadImageUrl;
    }

    /** The feed medium a scraped URL names, matched by image identity or bare URL, or null when the feed did not enumerate it. */
    public function declaredMediumFor(string $url): ?EntryMedium
    {
        $identity = ImageIdentityModel::fromUrl($url);
        $bare = self::bareUrl($url);
        foreach ($this->media as $medium) {
            if ($this->mediumMatches($medium, $url, $bare, $identity)) {
                return $medium;
            }
        }

        return null;
    }

    /** The feed attachment a scraped URL names, matched by bare URL, or null. */
    public function declaredAttachmentFor(string $url): ?EntryAttachment
    {
        $bare = self::bareUrl($url);
        foreach ($this->attachments as $attachment) {
            if (self::bareUrl($attachment->url) === $bare) {
                return $attachment;
            }
        }

        return null;
    }

    private function mediumMatches(EntryMedium $medium, string $url, string $bare, ImageIdentityModel $identity): bool
    {
        if ($medium->kind === 'image') {
            return ImageIdentityModel::fromUrl($medium->url)->isSameAsset($identity);
        }

        return self::bareUrl($medium->url) === $bare;
    }

    /** A URL stripped of its disposable query, so a signed or tracked rendition still matches its declaration. */
    private static function bareUrl(string $url): string
    {
        $query = strpos($url, '?');

        return $query === false ? $url : substr($url, 0, $query);
    }
}
