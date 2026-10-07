<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Support\PodcastArtwork;

/**
 * Each format's image order; the body image stands in for a missing declared one, or lends it its renditions.
 * Podcast artwork comes last: many hosts repeat the show's art on every item, which must not hide the post's picture.
 */
final readonly class FeedItemImageSelector
{
    public function __construct(private ItemImageExtractor $extractor)
    {
    }

    public function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $declared = $this->extractor->fromMedia($item)
            ?? $this->extractor->fromRssEnclosure($item)
            ?? $this->extractor->fromCustomImageElement($item);

        return self::withBodyImage($declared, $this->extractor->fromHtml($bodyHtml)) ?? PodcastArtwork::of($item);
    }

    public function fromRss1(\DOMElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $declared = $this->extractor->fromMedia($item) ?? $this->extractor->fromCustomImageElement($item);

        return self::withBodyImage($declared, $this->extractor->fromHtml($bodyHtml));
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public function fromAtom(
        \DOMElement $entry,
        string $namespace,
        array $bodyHtmlCandidates,
    ): ?DeclaredImageModel {
        $declared = $this->extractor->fromMedia($entry)
            ?? $this->extractor->fromAtomEnclosure($entry, $namespace)
            ?? $this->extractor->fromCustomImageElement($entry);

        return self::withBodyImage($declared, $this->firstBodyImage($bodyHtmlCandidates)) ?? PodcastArtwork::of($entry);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    private function firstBodyImage(array $bodyHtmlCandidates): ?DeclaredImageModel
    {
        foreach ($bodyHtmlCandidates as $bodyHtml) {
            $image = $this->extractor->fromHtml($bodyHtml);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    private static function withBodyImage(
        ?DeclaredImageModel $declared,
        ?DeclaredImageModel $bodyImage,
    ): ?DeclaredImageModel {
        if ($declared === null) {
            return $bodyImage;
        }

        return $bodyImage === null ? $declared : $declared->joinedWith($bodyImage);
    }
}
