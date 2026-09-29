<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\Model\DeclaredImageModel;

final readonly class FeedItemImageSelector
{
    public function __construct(private ItemImageExtractor $extractor)
    {
    }

    public function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $image = $this->extractor->fromMedia($item) ?? $this->extractor->fromRssEnclosure($item);

        return $image
            ?? $this->extractor->fromCustomImageElement($item)
            ?? $this->extractor->fromHtml($bodyHtml);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public function fromAtom(
        \DOMElement $entry,
        string $namespace,
        array $bodyHtmlCandidates,
    ): ?DeclaredImageModel {
        $image = $this->extractor->fromMedia($entry) ?? $this->extractor->fromAtomEnclosure($entry, $namespace);

        return $image
            ?? $this->extractor->fromCustomImageElement($entry)
            ?? $this->firstBodyImage($bodyHtmlCandidates);
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
}
