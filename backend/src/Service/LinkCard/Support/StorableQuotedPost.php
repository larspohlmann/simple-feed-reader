<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Entity\EntryMedium;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Service\Text\Support\WordBoundary;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\HttpsImageUrl;

/**
 * A quoted post as an entry may hold it, whichever network or file it came from: none without a web address that
 * fits or without a handle; the rest cut to its columns.
 */
final class StorableQuotedPost
{
    public static function of(QuotedPostContent $quote): ?QuotedPostContent
    {
        $handle = mb_substr(trim($quote->authorHandle), 0, QuotedPostContent::NAME_MAX);
        if (!self::isWebPage($quote->url) || $handle === '') {
            return null;
        }
        $name = mb_substr(trim($quote->authorName), 0, QuotedPostContent::NAME_MAX);

        return new QuotedPostContent(
            $quote->url,
            $name === '' ? $handle : $name,
            $handle,
            HttpsImageUrl::orNull($quote->authorAvatarUrl),
            $quote->postedAt?->setTimezone(new \DateTimeZone('UTC')),
            WordBoundary::clipped($quote->text, QuotedPostContent::TEXT_MAX),
            self::storableMedia($quote->media),
            self::storableReference($quote->nestedQuote),
        );
    }

    /**
     * @param list<EntryMedium> $media
     *
     * @return list<EntryMedium>
     */
    private static function storableMedia(array $media): array
    {
        $kept = [];
        foreach ($media as $medium) {
            if (HttpsImageUrl::orNull($medium->url) !== $medium->url) {
                continue;
            }
            $kept[] = new EntryMedium(
                $medium->url,
                $medium->kind,
                $medium->width,
                $medium->height,
                HttpsImageUrl::orNull($medium->previewImageUrl),
                $medium->alt,
            );
        }

        return \array_slice($kept, 0, QuotedPostContent::MEDIA_MAX);
    }

    private static function storableReference(?QuotedPostReference $reference): ?QuotedPostReference
    {
        if ($reference === null || !self::isWebPage($reference->url)) {
            return null;
        }
        $handle = mb_substr(trim((string) $reference->handle), 0, QuotedPostContent::NAME_MAX);

        return new QuotedPostReference($reference->url, $handle === '' ? null : $handle);
    }

    private static function isWebPage(string $url): bool
    {
        return mb_strlen($url) <= QuotedPostContent::URL_MAX && AbsoluteHttpUrl::matches($url);
    }

    private function __construct()
    {
    }
}
