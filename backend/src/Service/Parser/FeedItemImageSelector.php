<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\DeclaredImage;

final class FeedItemImageSelector
{
    public static function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImage
    {
        $image = ItemImageExtractor::fromMedia($item) ?? ItemImageExtractor::fromRssEnclosure($item);

        return $image
            ?? ItemImageExtractor::fromCustomImageElement($item)
            ?? ItemImageExtractor::fromHtml($bodyHtml);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public static function fromAtom(\DOMElement $entry, string $namespace, array $bodyHtmlCandidates): ?DeclaredImage
    {
        $image = ItemImageExtractor::fromMedia($entry) ?? ItemImageExtractor::fromAtomEnclosure($entry, $namespace);

        return $image
            ?? ItemImageExtractor::fromCustomImageElement($entry)
            ?? self::firstBodyImage($bodyHtmlCandidates);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    private static function firstBodyImage(array $bodyHtmlCandidates): ?DeclaredImage
    {
        foreach ($bodyHtmlCandidates as $bodyHtml) {
            $image = ItemImageExtractor::fromHtml($bodyHtml);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }
}
