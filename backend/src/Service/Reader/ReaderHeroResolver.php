<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Entry;

/**
 * Picks the hero for each body the reader can show.
 *
 * The reader view renders the extracted article; the original view renders the
 * feed's own body. Each has its own leading picture, and the duplicate rule has
 * to be judged against the body that will actually be on screen. Resolving both
 * here is what lets the rule live in exactly one place (#592): the client picks
 * a field, it does not decide anything.
 */
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
     * not — the page offered none, or the extracted body already shows it — the
     * feed's picture is offered against that same body rather than leaving the
     * article imageless.
     */
    private function readerHero(ExtractionResult $result, ?HeroImage $feedPicture): ?HeroImage
    {
        if (!$result->ok) {
            return null;
        }

        $extractedBody = (string) $result->contentHtml;

        return $this->selector->select($this->extractedPicture($result), $extractedBody)
            ?? $this->selector->select($feedPicture, $extractedBody);
    }

    /** Readability reports no dimensions for the og:image it finds. */
    private function extractedPicture(ExtractionResult $result): ?HeroImage
    {
        return $result->image === null ? null : new HeroImage($result->image);
    }

    private function feedPicture(Entry $entry): ?HeroImage
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new HeroImage($url, $entry->getImageWidth(), $entry->getImageHeight());
    }

    /**
     * The body the original view renders. Many feeds populate only one of
     * contentHtml and summary, so the client falls through both; the rule has to
     * judge the same string.
     */
    private function feedBody(Entry $entry): string
    {
        return $entry->getContentHtml() ?? $entry->getSummary() ?? '';
    }
}
