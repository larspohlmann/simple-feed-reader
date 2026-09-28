<?php

declare(strict_types=1);

namespace App\Service\Reading;

/** A partial state change: a null flag stays as it is. */
final readonly class EntryStateChange
{
    public function __construct(
        public ?bool $isHidden = null,
        public ?bool $isFavorite = null,
        public ?bool $isKept = null,
        public ?bool $isViewed = null,
    ) {
    }
}
