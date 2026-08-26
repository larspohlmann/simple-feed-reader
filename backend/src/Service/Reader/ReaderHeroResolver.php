<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;
use App\Service\Image\DeclaredImage;

/** Picks the hero for each body the reader can show. */
final readonly class ReaderHeroResolver
{
    public function __construct(private HeroImageSelector $selector)
    {
    }

    public function resolve(Entry $entry, ExtractionResult $result): ReaderHeroes
    {
        $feedPicture = $this->feedPicture($entry);

        return new ReaderHeroes(
            readerHero: $this->readerHero($result, $feedPicture),
            originalHero: $this->selector->select($feedPicture, $this->feedBody($entry)),
        );
    }

    /**
     * The extraction's own picture leads when it survives the rule. When it does
     * not — the page offered none, or the extracted body already shows an image —
     * the feed's picture is offered against that same body rather than leaving
     * the article imageless.
     */
    private function readerHero(ExtractionResult $result, ?DeclaredImage $feedPicture): ?DeclaredImage
    {
        if (!$result->ok) {
            return null;
        }

        $extractedBody = (string) $result->contentHtml;

        return $this->selector->select($this->extractedPicture($result), $extractedBody)
            ?? $this->selector->select($feedPicture, $extractedBody);
    }

    /** Readability reports no dimensions for the og:image it finds. */
    private function extractedPicture(ExtractionResult $result): ?DeclaredImage
    {
        return $result->imageCandidate === null ? null : new DeclaredImage($result->imageCandidate);
    }

    private function feedPicture(Entry $entry): ?DeclaredImage
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImage($url, $entry->getImageWidth(), $entry->getImageHeight());
    }

    /** The body the original view renders. */
    private function feedBody(Entry $entry): string
    {
        return $entry->getContentHtml() ?? $entry->getSummary() ?? '';
    }
}
