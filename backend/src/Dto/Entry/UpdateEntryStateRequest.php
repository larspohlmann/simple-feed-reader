<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use App\Service\Reader\EntryStateChange;

/**
 * Partial update: a null field means "leave unchanged". At least one non-null
 * field is expected, but an all-null body is a harmless no-op, not an error.
 */
final readonly class UpdateEntryStateRequest
{
    public function __construct(
        public ?bool $isHidden = null,
        public ?bool $isFavorite = null,
        public ?bool $isKept = null,
        // Both directions (#482): true opens/reads the entry, false un-ticks it.
        // Setting viewed also hides (ViewedImpliesHiddenListener); un-ticking
        // leaves the entry hidden.
        public ?bool $isViewed = null,
    ) {
    }

    public function toChange(): EntryStateChange
    {
        return new EntryStateChange(
            isHidden: $this->isHidden,
            isFavorite: $this->isFavorite,
            isKept: $this->isKept,
            isViewed: $this->isViewed,
        );
    }
}
