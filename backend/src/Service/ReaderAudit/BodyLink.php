<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * One anchor of the cleaned article: where it points and what it reads as.
 *
 * Not every anchor is a link out. An article's own table of contents points at
 * its sections, and those arrive with an empty or fragment-only href —
 * deutschlandfunk.de renders one on every long piece, and counting its five
 * entries as a menu reported the article itself as chrome (#744).
 */
final readonly class BodyLink
{
    public function __construct(
        public string $href,
        public string $text,
    ) {
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->href, \PHP_URL_HOST));
    }

    /** Whether following this anchor would leave the article at all. */
    public function leavesThePage(): bool
    {
        return $this->href !== '' && !str_starts_with($this->href, '#');
    }
}
