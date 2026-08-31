<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * What stands between the top of the reader view and the article's first
 * paragraph. This is the half of the audit that matters: a menu, a ressort list
 * or a run of bare links above the first sentence is what a reader hits, while
 * the same shapes under the last paragraph are the site's related-articles tail
 * and are tolerated (#744).
 *
 * Every rule here reads ExtractedBody::leadingBlocks() and nothing else, so no
 * rule can be fooled by furniture that sits safely at the end.
 */
final readonly class LeadingChromeMarkers
{
    /** Offending lines quoted in a finding's detail, so it can be judged without opening the page. */
    private const int QUOTED_LINES = 3;

    private const int MIN_LIST_ITEMS = 3;
    private const int MIN_NAV_RUN = 4;
    private const int MIN_LEADING_LINKS = 5;
    private const int MIN_LEADING_BLOCKS_FOR_WALL = 6;

    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body): array
    {
        $leading = $body->leadingBlocks();
        $candidates = [
            $this->linkList($leading),
            $this->navigationRun($leading),
            $this->linkWall($leading),
        ];

        return array_values(array_filter($candidates));
    }

    /**
     * A list whose items are bare links, standing before the article starts: a
     * site menu, a ressort row or a "most read" box readability kept.
     *
     * @param list<BodyBlock> $leading
     */
    private function linkList(array $leading): ?CleanupMarker
    {
        $items = [];
        foreach ($leading as $block) {
            if ($block->tag === 'li' && $block->isChrome()) {
                $items[] = $block->text;
            }
        }
        if (\count($items) < self::MIN_LIST_ITEMS) {
            return null;
        }

        return new CleanupMarker(
            'leading_link_list',
            4,
            'NavigationChromeTrimmer',
            \sprintf(
                '%d link-only list items stand before the first paragraph: %s',
                \count($items),
                $this->quoted($items),
            ),
        );
    }

    /**
     * The same shape without a list: consecutive blocks that are nothing but a
     * link, which is how a menu built from <div>s or <p>s arrives.
     *
     * @param list<BodyBlock> $leading
     */
    private function navigationRun(array $leading): ?CleanupMarker
    {
        $run = [];
        $longest = [];
        foreach ($leading as $block) {
            $run = $block->isChrome() && $block->tag !== 'li' ? [...$run, $block->text] : [];
            $longest = \count($run) > \count($longest) ? $run : $longest;
        }
        if (\count($longest) < self::MIN_NAV_RUN) {
            return null;
        }

        return new CleanupMarker(
            'leading_nav_run',
            4,
            'NavigationChromeTrimmer',
            \sprintf(
                '%d consecutive link-only blocks before the first paragraph: %s',
                \count($longest),
                $this->quoted($longest),
            ),
        );
    }

    /**
     * Neither shape, but the reader still has to scroll past a wall of links to
     * reach the first sentence.
     *
     * @param list<BodyBlock> $leading
     */
    private function linkWall(array $leading): ?CleanupMarker
    {
        $links = 0;
        foreach ($leading as $block) {
            $links += $block->outboundLinks();
        }
        if ($links < self::MIN_LEADING_LINKS || \count($leading) < self::MIN_LEADING_BLOCKS_FOR_WALL) {
            return null;
        }

        return new CleanupMarker(
            'leading_link_wall',
            3,
            'NavigationChromeTrimmer',
            \sprintf(
                '%d links across %d blocks before the article starts: %s',
                $links,
                \count($leading),
                $this->quoted(array_map(static fn (BodyBlock $block): string => $block->text, $leading)),
            ),
        );
    }

    /** @param list<string> $lines */
    private function quoted(array $lines): string
    {
        $shown = \array_slice($lines, 0, self::QUOTED_LINES);
        $quoted = implode(' | ', array_map(static fn (string $line): string => '"' . $line . '"', $shown));

        return \count($lines) > self::QUOTED_LINES ? $quoted . ' | …' : $quoted;
    }
}
