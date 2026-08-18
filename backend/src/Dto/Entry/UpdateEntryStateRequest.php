<?php

declare(strict_types=1);

namespace App\Dto\Entry;

/**
 * Partial update: a null field means "leave unchanged". At least one non-null
 * field is expected, but an all-null body is a harmless no-op, not an error.
 */
final readonly class UpdateEntryStateRequest
{
    public function __construct(
        public ?bool $isRead = null,
        public ?bool $isFavorite = null,
        public ?bool $isKept = null,
        // Both directions (#482): true opens/reads the entry, false un-ticks it.
        // Setting viewed also reads (ViewedImpliesReadListener); un-ticking
        // leaves the entry read.
        public ?bool $isViewed = null,
    ) {
    }
}
