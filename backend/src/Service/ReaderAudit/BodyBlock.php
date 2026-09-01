<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\LeadingEngagementRules;

/**
 * One paragraph-level line of the cleaned article, with enough about its links
 * to say whether it is prose or chrome. Where a block sits matters more than
 * what it holds: a related-articles list under the last paragraph is furniture
 * the reader tolerates, and the same list above the first paragraph is the
 * article failing to start (#744).
 */
final readonly class BodyBlock
{
    /** A block whose text is this share links is a menu entry, not a sentence. */
    private const float LINK_DOMINATED = 0.8;

    /** @param list<BodyLink> $links */
    public function __construct(
        public string $tag,
        public string $text,
        public array $links,
        public bool $isTimeOnly = false,
    ) {
    }

    /** Links that would take the reader off this page — the only ones chrome is made of. */
    public function outboundLinks(): int
    {
        return \count(array_filter($this->links, static fn (BodyLink $link): bool => $link->leavesThePage()));
    }

    public function length(): int
    {
        return mb_strlen($this->text);
    }

    /** Mostly link text of any kind: not a sentence, so not where the article begins. */
    public function isLinkDominated(): bool
    {
        return $this->dominatedBy(static fn (BodyLink $link): bool => true);
    }

    /**
     * Mostly links that leave the page: a menu entry, a share button, a teaser
     * row. An article's own table of contents is link-dominated but not this —
     * its entries go nowhere but further down the same article (#744).
     */
    public function isChrome(): bool
    {
        return $this->dominatedBy(static fn (BodyLink $link): bool => $link->leavesThePage());
    }

    /** @param callable(BodyLink): bool $counts */
    private function dominatedBy(callable $counts): bool
    {
        if ($this->length() === 0) {
            return false;
        }

        $linked = 0;
        foreach ($this->links as $link) {
            $linked += $counts($link) ? mb_strlen($link->text) : 0;
        }

        return $linked / $this->length() >= self::LINK_DOMINATED;
    }

    /**
     * A block that is a link back into this same page: a skip link, a table of
     * contents entry, a back-to-top. It reads like chrome and is the page's own
     * affordance, so no rule should score it (#744).
     */
    public function isInPageAffordance(): bool
    {
        return $this->isLinkDominated() && !$this->isChrome();
    }

    public function isHeading(): bool
    {
        return \in_array($this->tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
    }

    /** The first block that answers true is where the article begins. */
    public function isProse(): bool
    {
        return LeadingEngagementRules::isProse($this->text, $this->linkedTextLength());
    }

    private function linkedTextLength(): int
    {
        $length = 0;
        foreach ($this->links as $link) {
            $length += mb_strlen($link->text);
        }

        return $length;
    }
}
