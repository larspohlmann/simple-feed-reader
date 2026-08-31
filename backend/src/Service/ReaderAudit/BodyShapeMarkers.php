<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * The whole-body rules: not "the cleaners left something behind" but "this is
 * not the article". A body with no paragraph at all, more headings than
 * paragraphs, or several articles' worth of text is a region readability picked
 * wrongly, and no amount of trimming fixes it.
 *
 * Deliberately blind to what sits after the last paragraph. An audit that scored
 * the tail reported every well-cleaned page on sites that end with a
 * related-articles box, which is most of them (#744).
 */
final readonly class BodyShapeMarkers
{
    private const int HUGE_BODY_CHARS = 40_000;
    private const int SUBSTANTIAL_FEED_CHARS = 800;
    private const float FEED_SHORTFALL = 0.6;
    private const int MIN_HEADINGS_FOR_HUB = 3;

    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body, SampledEntry $entry, ?string $articleTitle): array
    {
        $candidates = [
            $this->noParagraphs($body),
            $this->headingHeavy($body),
            $this->hugeBody($body),
            $this->belowFeedBody($body, $entry),
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

    private function headingHeavy(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->headingCount < self::MIN_HEADINGS_FOR_HUB || $body->headingCount <= $body->paragraphCount) {
            return null;
        }

        return new CleanupMarker(
            'heading_heavy',
            3,
            'readability picked an index page',
            \sprintf('%d headings against %d paragraphs', $body->headingCount, $body->paragraphCount)
        );
    }

    private function hugeBody(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->textLength() <= self::HUGE_BODY_CHARS) {
            return null;
        }

        return new CleanupMarker(
            'body_huge',
            2,
            'readability picked a section or index page',
            \sprintf('%d characters — more than one article', $body->textLength())
        );
    }

    /**
     * The reader exists to add to the feed body. Falling below it means a cleaner
     * cut article text, not furniture.
     */
    private function belowFeedBody(ExtractedBody $body, SampledEntry $entry): ?CleanupMarker
    {
        $feedLength = mb_strlen($this->plainText($entry->feedContentHtml));
        if ($feedLength < self::SUBSTANTIAL_FEED_CHARS) {
            return null;
        }
        if ($body->textLength() >= (int) ($feedLength * self::FEED_SHORTFALL)) {
            return null;
        }

        return new CleanupMarker(
            'body_below_feed',
            3,
            'the cleaners over-trimmed',
            \sprintf('reader shows %d chars, the feed body already has %d', $body->textLength(), $feedLength)
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

    private function titleKey(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($text));
    }

    private function plainText(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    }
}
