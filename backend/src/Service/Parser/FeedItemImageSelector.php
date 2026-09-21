<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;

/**
 * Chooses one image for a feed item across its sources, in precedence order
 * (Media RSS, format enclosure, custom <image>, inline body <img>). A source
 * that is already https wins over an earlier http one, because the http URL is
 * only upgraded optimistically and may not be reachable; the first http
 * candidate is the fallback when nothing native-https is found.
 */
final class FeedItemImageSelector
{
    public static function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImage
    {
        return self::preferNativeHttps([
            static fn (): ?DeclaredImage => ItemImageExtractor::fromMedia($item),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromRssEnclosure($item),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromCustomImageElement($item),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromHtml($bodyHtml),
        ]);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public static function fromAtom(\DOMElement $entry, string $namespace, array $bodyHtmlCandidates): ?DeclaredImage
    {
        $sources = [
            static fn (): ?DeclaredImage => ItemImageExtractor::fromMedia($entry),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromAtomEnclosure($entry, $namespace),
            static fn (): ?DeclaredImage => ItemImageExtractor::fromCustomImageElement($entry),
        ];
        foreach ($bodyHtmlCandidates as $bodyHtml) {
            $sources[] = static fn (): ?DeclaredImage => ItemImageExtractor::fromHtml($bodyHtml);
        }

        return self::preferNativeHttps($sources);
    }

    /** @param list<callable(): ?DeclaredImage> $sources */
    private static function preferNativeHttps(array $sources): ?DeclaredImage
    {
        $upgradeCandidate = null;
        foreach ($sources as $source) {
            $image = $source();
            if ($image === null) {
                continue;
            }
            if (self::isNativeHttps($image->url)) {
                return $image;
            }
            $upgradeCandidate ??= $image;
        }

        return $upgradeCandidate;
    }

    private static function isNativeHttps(string $url): bool
    {
        return str_starts_with($url, 'https://') || str_starts_with($url, '//');
    }
}
