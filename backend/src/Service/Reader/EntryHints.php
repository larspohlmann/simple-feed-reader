<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * What the feed entry tells the extractor: its title (to drop a headline repeated in the body), its author, and the
 * media it declared, trusted over the reader's guesses for a scraped URL it enumerated (#914).
 */
final readonly class EntryHints
{
    public FeedMedia $feedMedia;

    public function __construct(
        public ?string $title = null,
        public ?string $author = null,
        ?FeedMedia $feedMedia = null,
    ) {
        $this->feedMedia = $feedMedia ?? FeedMedia::none();
    }
}
