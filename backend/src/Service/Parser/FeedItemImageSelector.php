<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;
use App\Service\Url\HttpsImageUrl;

/**
 * Picks one image per feed item across its sources in precedence order; a
 * native-https source wins over an earlier http one (optimistic upgrades may be
 * unreachable), with the first http candidate as the fallback.
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
            if (HttpsImageUrl::isNativeHttps($image->url)) {
                return $image;
            }
            $upgradeCandidate ??= $image;
        }

        return $upgradeCandidate;
    }
}
