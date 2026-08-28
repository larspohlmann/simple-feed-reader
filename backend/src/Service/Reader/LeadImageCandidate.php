<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * The lead image ReaderLeadImage may restore, with the evidence to decide it:
 * the og:image URL readability reported (null or non-http when there is none to
 * restore), and the inventory of images the page actually draws. Grouped so
 * ReaderBodyCleaner::clean carries one lead parameter, not two.
 */
final readonly class LeadImageCandidate
{
    public function __construct(
        public ?string $url,
        public PageImageInventory $pageImages,
    ) {
    }
}
