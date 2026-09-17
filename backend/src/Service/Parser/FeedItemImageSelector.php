<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;

final class FeedItemImageSelector
{
    public static function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImage
    {
        $image = ItemImageExtractor::fromMedia($item);
        if ($image !== null) {
            return $image;
        }

        $image = ItemImageExtractor::fromRssEnclosure($item);
        if ($image !== null) {
            return $image;
        }

        $image = ItemImageExtractor::fromCustomImageElement($item);
        if ($image !== null) {
            return $image;
        }

        return ItemImageExtractor::fromHtml($bodyHtml);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public static function fromAtom(
        \DOMElement $entry,
        string $namespace,
        array $bodyHtmlCandidates,
    ): ?DeclaredImage {
        $image = ItemImageExtractor::fromMedia($entry);
        if ($image !== null) {
            return $image;
        }

        $image = ItemImageExtractor::fromAtomEnclosure($entry, $namespace);
        if ($image !== null) {
            return $image;
        }

        $image = ItemImageExtractor::fromCustomImageElement($entry);
        if ($image !== null) {
            return $image;
        }

        foreach ($bodyHtmlCandidates as $bodyHtml) {
            $image = ItemImageExtractor::fromHtml($bodyHtml);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }
}
