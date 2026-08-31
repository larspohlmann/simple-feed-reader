<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\ImageIdentity;

/**
 * The shape-based half of the audit: what an article looks like when a cleaner
 * missed something, stated in counts rather than in words. A menu the trimmer
 * left behind is a run of link-dominated list items; a lead image restored twice
 * is two pictures with one identity; a hub page mistaken for an article has more
 * headings than paragraphs.
 *
 * Every rule answers with a marker or with null, and none of them reads another's
 * answer, so a page can be reported for several at once — which is the point:
 * the score that ranks the report is how many independent things look wrong.
 */
final readonly class StructureMarkers
{
    private const int SHORT_BODY_CHARS = 900;
    private const int HUGE_BODY_CHARS = 40_000;
    private const int SUBSTANTIAL_FEED_CHARS = 800;
    private const float FEED_SHORTFALL = 0.6;
    private const float LINK_HEAVY_RATIO = 0.35;
    private const int MIN_LINKS_FOR_DENSITY = 5;
    private const int MIN_LINK_LIST_ITEMS = 4;
    private const int MIN_HEADINGS_FOR_HUB = 3;
    private const int MIN_REPEATED_BLOCK_CHARS = 30;
    private const int MAX_SHOUTING_LINE_CHARS = 60;
    private const int MIN_SHOUTING_LINES = 3;

    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body, SampledEntry $entry, ?string $articleTitle): array
    {
        $candidates = [
            $this->shortBody($body),
            $this->belowFeedBody($body, $entry),
            $this->hugeBody($body),
            $this->noParagraphs($body),
            $this->linkDense($body),
            $this->linkList($body),
            $this->headingHeavy($body),
            $this->duplicateTitle($body, $entry, $articleTitle),
            $this->repeatedImage($body),
            $this->missingImage($body, $entry),
            $this->repeatedBlock($body),
            $this->shoutingLines($body),
            $this->truncatedTail($body),
        ];

        return array_values(array_filter($candidates));
    }

    private function shortBody(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->textLength() >= self::SHORT_BODY_CHARS) {
            return null;
        }

        return new CleanupMarker(
            'body_short',
            2,
            'ArticleExtractor / cleaners over-trimmed',
            \sprintf('%d characters of article text', $body->textLength())
        );
    }

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
            'ArticleExtractor / cleaners over-trimmed',
            \sprintf('reader shows %d chars, the feed body already has %d', $body->textLength(), $feedLength)
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

    private function noParagraphs(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->paragraphCount > 0 || $body->textLength() === 0) {
            return null;
        }

        return new CleanupMarker(
            'no_paragraphs',
            3,
            'readability picked a non-article region',
            'the body holds text but not one <p>'
        );
    }

    private function linkDense(ExtractedBody $body): ?CleanupMarker
    {
        if (\count($body->linkTexts) < self::MIN_LINKS_FOR_DENSITY || $body->linkTextRatio() < self::LINK_HEAVY_RATIO) {
            return null;
        }

        return new CleanupMarker(
            'link_dense',
            3,
            'NavigationChromeTrimmer',
            \sprintf(
                '%d links carry %d%% of the text',
                \count($body->linkTexts),
                (int) round($body->linkTextRatio() * 100),
            )
        );
    }

    private function linkList(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->linkDominatedListItems < self::MIN_LINK_LIST_ITEMS) {
            return null;
        }

        return new CleanupMarker(
            'link_list',
            3,
            'NavigationChromeTrimmer / EdgeBoilerplateTrimmer',
            \sprintf('%d list items are nothing but a link', $body->linkDominatedListItems)
        );
    }

    private function headingHeavy(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->headingCount < self::MIN_HEADINGS_FOR_HUB || $body->headingCount <= $body->paragraphCount) {
            return null;
        }

        return new CleanupMarker(
            'heading_heavy',
            2,
            'readability picked an index page',
            \sprintf('%d headings against %d paragraphs', $body->headingCount, $body->paragraphCount)
        );
    }

    private function duplicateTitle(ExtractedBody $body, SampledEntry $entry, ?string $articleTitle): ?CleanupMarker
    {
        $first = $body->blockTexts[0] ?? null;
        if ($first === null) {
            return null;
        }

        $firstKey = $this->titleKey($first);
        if ($firstKey === '') {
            return null;
        }
        foreach ([$entry->title, $articleTitle] as $title) {
            if ($title !== null && $this->titleKey($title) === $firstKey) {
                return new CleanupMarker(
                    'duplicate_title',
                    2,
                    'LeadingTitleRemover',
                    \sprintf('body opens with the headline again: %s', $first)
                );
            }
        }

        return null;
    }

    private function repeatedImage(ExtractedBody $body): ?CleanupMarker
    {
        $seen = [];
        foreach ($body->imageSources as $source) {
            $identity = ImageIdentity::fromUrl($source);
            foreach ($seen as $earlier) {
                if ($identity->matches($earlier)) {
                    return new CleanupMarker(
                        'repeated_image',
                        2,
                        'ReaderLeadImage',
                        \sprintf('the same picture appears twice: %s', $source)
                    );
                }
            }
            $seen[] = $identity;
        }

        return null;
    }

    private function missingImage(ExtractedBody $body, SampledEntry $entry): ?CleanupMarker
    {
        if (!$entry->hasFeedImage || $body->imageSources !== []) {
            return null;
        }

        return new CleanupMarker(
            'image_missing',
            1,
            'ReaderLeadImage',
            'the feed carries a lead image, the reader body shows none'
        );
    }

    private function repeatedBlock(ExtractedBody $body): ?CleanupMarker
    {
        $counts = [];
        foreach ($body->blockTexts as $text) {
            if (mb_strlen($text) < self::MIN_REPEATED_BLOCK_CHARS) {
                continue;
            }
            $counts[$text] = ($counts[$text] ?? 0) + 1;
            if ($counts[$text] > 1) {
                return new CleanupMarker(
                    'repeated_block',
                    2,
                    'ArticleExtractor duplicated a block',
                    \sprintf('twice in the body: %s', mb_substr($text, 0, 120))
                );
            }
        }

        return null;
    }

    private function shoutingLines(ExtractedBody $body): ?CleanupMarker
    {
        $shouting = 0;
        foreach ($body->blockTexts as $text) {
            if (mb_strlen($text) <= self::MAX_SHOUTING_LINE_CHARS && $text !== '' && mb_strtoupper($text) === $text) {
                ++$shouting;
            }
        }
        if ($shouting < self::MIN_SHOUTING_LINES) {
            return null;
        }

        return new CleanupMarker(
            'shouting_lines',
            1,
            'EdgeBoilerplateTrimmer',
            \sprintf('%d short all-caps lines — section labels, not prose', $shouting)
        );
    }

    private function truncatedTail(ExtractedBody $body): ?CleanupMarker
    {
        if ($body->textLength() === 0) {
            return null;
        }
        $last = mb_substr($body->text, -1);
        // A caption or a credit legitimately closes on a bracket, so those count
        // as an ending too; only a body that stops mid-word is the signal.
        if (str_contains('.!?…"»”’)›]', $last)) {
            return null;
        }

        return new CleanupMarker(
            'truncated_tail',
            1,
            'EdgeBoilerplateTrimmer over-trimmed the end',
            \sprintf('body ends mid-sentence: …%s', mb_substr($body->text, -60))
        );
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
