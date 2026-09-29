<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/**
 * One anchor of the cleaned article. An empty or fragment-only href points into the article itself (its table of
 * contents), and counting those as a menu reported the article as chrome (#746).
 */
final readonly class BodyLinkModel
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
