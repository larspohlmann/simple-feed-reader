<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * The lead image ReaderLeadImage may restore, with the evidence to decide it:
 * the og:image URL readability reported (null or non-http when there is none to
 * restore), the inventory of images the page actually draws, and the caption
 * its dropped figure carried, if any. Grouped so ReaderBodyCleaner::clean
 * carries one lead parameter, not three.
 */
final readonly class LeadImageCandidate
{
    public function __construct(
        public ?string $url,
        public PageImageInventory $pageImages,
        public ?string $caption = null,
    ) {
    }
}
