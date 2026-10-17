<?php

declare(strict_types=1);

namespace App\Entity;

/** Where a saved copy of an article came from: the feed's title and icon at the time it was left (#1666). */
final readonly class EntryOrigin
{
    public function __construct(
        public ?string $title,
        public ?string $faviconUrl,
    ) {
    }
}
