<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * The lead image ReaderLeadImage may restore, with the evidence to decide it: readability's og:image URL (null or
 * non-http when there is none), the images the page draws, and the caption its dropped figure carried.
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
