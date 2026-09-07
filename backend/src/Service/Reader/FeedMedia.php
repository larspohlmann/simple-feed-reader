<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;

/**
 * The media the feed already declared for one entry, matched against the URLs
 * the reader scrapes from the article page (#914). The feed states a media
 * item's kind, MIME, and pixel size authoritatively; when a scraped URL is one
 * the feed enumerated, the reader trusts those over its own extension-and-query
 * guesses — real dimensions against layout shift, the declared kind over a
 * sniff. No outbound HTTP: every URL and value is already persisted.
 *
 * Also the home of the #913 poster fallback, since it is the same feed-declared
 * media read for the same entry.
 */
final readonly class FeedMedia
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

    /** The still the reader gives a poster-less video: a video medium's own preview, else the lead image (#913). */
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
        $identity = ImageIdentity::fromUrl($url);
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

    private function mediumMatches(EntryMedium $medium, string $url, string $bare, ImageIdentity $identity): bool
    {
        if ($medium->kind === 'image') {
            return ImageIdentity::fromUrl($medium->url)->isSameAsset($identity);
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
