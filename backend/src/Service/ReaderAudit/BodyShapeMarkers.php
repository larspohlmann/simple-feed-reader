<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * The whole-body rules: not "the cleaners left something behind" but "this is
 * not the article". A body with no paragraph at all, more headings than
 * paragraphs, or several articles' worth of text is a region readability
 * picked wrongly, and no amount of trimming fixes it.
 *
 * Deliberately blind to what sits after the last paragraph — scoring the tail
 * flagged every well-cleaned page ending in a related-articles box, which is
 * most of them (#744).
 *
 * Length alone says nothing, in either direction: a Volts podcast transcript
 * is 88,000 characters and is one article, while a feed body longer than its
 * article is often a fuller press release, not cut prose — indistinguishable
 * by length, a case the coverage gate already guards (#744).
 */
final readonly class BodyShapeMarkers
{
    private const int MIN_HEADINGS_FOR_HUB = 3;

    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body, SampledEntry $entry, ?string $articleTitle): array
    {
        $candidates = [
            $this->noParagraphs($body),
            $this->headingHeavy($body),
            $this->duplicateTitle($body, $entry, $articleTitle),
        ];

        return array_values(array_filter($candidates));
    }

    private function noParagraphs(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->paragraphCount > 0 || $body->textLength() === 0) {
            return null;
        }

        return new CleanupMarker(
            'no_paragraphs',
            4,
            'readability picked a non-article region',
            'the body holds text but not one <p>'
        );
    }

    /**
     * An index page is not a page with many headings — a sectioned essay has
     * those, and counting them reported an Anarchist Library pamphlet with 19
     * section titles. What an index page has is headings that are LINKS, each
     * one a teaser for a different article (#744).
     */
    private function headingHeavy(ExtractedBody $body): ?CleanupMarker
    {
        $linkedHeadings = 0;
        foreach ($body->blocks as $block) {
            $linkedHeadings += $block->isHeading() && $block->isChrome() ? 1 : 0;
        }
        if ($linkedHeadings < self::MIN_HEADINGS_FOR_HUB || $linkedHeadings <= $body->paragraphCount) {
            return null;
        }

        return new CleanupMarker(
            'heading_heavy',
            3,
            'readability picked an index page',
            \sprintf(
                '%d headings are links to other articles, against %d paragraphs',
                $linkedHeadings,
                $body->paragraphCount,
            ),
        );
    }

    private function duplicateTitle(ExtractedBody $body, SampledEntry $entry, ?string $articleTitle): ?CleanupMarker
    {
        $first = $body->blocks[0] ?? null;
        if ($first === null) {
            return null;
        }

        $firstKey = $this->titleKey($first->text);
        if ($firstKey === '') {
            return null;
        }
        foreach ([$entry->title, $articleTitle] as $title) {
            if ($title !== null && $this->titleKey($title) === $firstKey) {
                return new CleanupMarker(
                    'duplicate_title',
                    2,
                    'LeadingTitleRemover',
                    'body opens with the headline again: ' . $first->text
                );
            }
        }

        return null;
    }

    /**
     * The headline reduced to its words. Deliberately not a letters-only smash:
     * deutschlandfunk.de runs its kicker straight into the headline
     * ("Privatsphäre im AltenheimDer Abschied…") where the feed writes them with
     * a separator, and stripping every non-letter made those two identical (#744).
     */
    private function titleKey(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY);

        return implode(' ', $words === false ? [] : $words);
    }
}
