<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\ReaderAudit\Model\CleanupMarkerModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Service\ReaderAudit\Model\SampledEntryModel;

/**
 * The whole-body rules: not "the cleaners left something behind" but "readability picked something that is not the
 * article". They never score the tail (see LeadingChromeMarkers) or length, which says nothing either way (#746).
 */
final readonly class BodyShapeMarkers
{
    private const int MIN_HEADINGS_FOR_HUB = 3;

    /** @return list<CleanupMarkerModel> */
    public function detect(ExtractedBodyModel $body, SampledEntryModel $entry, ?string $articleTitle): array
    {
        $candidates = [
            $this->noParagraphs($body),
            $this->headingHeavy($body),
            $this->duplicateTitle($body, $entry, $articleTitle),
        ];

        return array_values(array_filter($candidates));
    }

    private function noParagraphs(ExtractedBodyModel $body): ?CleanupMarkerModel
    {
        if ($body->paragraphCount > 0 || $body->textLength() === 0) {
            return null;
        }

        return new CleanupMarkerModel(
            'no_paragraphs',
            4,
            'readability picked a non-article region',
            'the body holds text but not one <p>'
        );
    }

    /**
     * An index page's headings are links, each a teaser for another article; a sectioned essay's plain headings are
     * not (#746).
     */
    private function headingHeavy(ExtractedBodyModel $body): ?CleanupMarkerModel
    {
        $linkedHeadings = 0;
        foreach ($body->blocks as $block) {
            $linkedHeadings += $block->isHeading() && $block->isChrome() ? 1 : 0;
        }
        if ($linkedHeadings < self::MIN_HEADINGS_FOR_HUB || $linkedHeadings <= $body->paragraphCount) {
            return null;
        }

        return new CleanupMarkerModel(
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

    private function duplicateTitle(
        ExtractedBodyModel $body,
        SampledEntryModel $entry,
        ?string $articleTitle,
    ): ?CleanupMarkerModel {
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
                return new CleanupMarkerModel(
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
     * The headline reduced to its words, not its letters: a kicker run into the headline ("…AltenheimDer…") must not
     * equal the feed's title written with a separator (#746).
     */
    private function titleKey(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY);

        return implode(' ', $words === false ? [] : $words);
    }
}
