<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/** One paragraph-level line of the cleaned article, with its links, to tell prose from chrome. */
final readonly class BodyBlockModel
{
    /** A block whose text is this share links is a menu entry, not a sentence. */
    private const float LINK_DOMINATED = 0.8;

    /** @param list<BodyLinkModel> $links */
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
        return \count(array_filter($this->links, static fn (BodyLinkModel $link): bool => $link->leavesThePage()));
    }

    public function length(): int
    {
        return mb_strlen($this->text);
    }

    /** Mostly link text of any kind: not a sentence, so not where the article begins. */
    public function isLinkDominated(): bool
    {
        return $this->dominatedBy(static fn (BodyLinkModel $link): bool => true);
    }

    /** Mostly links that leave the page: a menu entry, a share button, a teaser row. */
    public function isChrome(): bool
    {
        return $this->dominatedBy(static fn (BodyLinkModel $link): bool => $link->leavesThePage());
    }

    /** @param callable(BodyLinkModel): bool $counts */
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

    /** A link back into this page (a skip link, a table-of-contents entry, a back-to-top): no rule scores it. */
    public function isInPageAffordance(): bool
    {
        return $this->isLinkDominated() && !$this->isChrome();
    }

    public function isHeading(): bool
    {
        return \in_array($this->tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
    }

    public function linkedTextLength(): int
    {
        $length = 0;
        foreach ($this->links as $link) {
            $length += mb_strlen($link->text);
        }

        return $length;
    }
}
