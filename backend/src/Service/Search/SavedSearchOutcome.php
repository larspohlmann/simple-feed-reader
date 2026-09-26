<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;

final readonly class SavedSearchOutcome
{
    private function __construct(
        public SavedSearch $savedSearch,
        public bool $isNew,
    ) {
    }

    public static function created(SavedSearch $savedSearch): self
    {
        return new self($savedSearch, true);
    }

    public static function existing(SavedSearch $savedSearch): self
    {
        return new self($savedSearch, false);
    }
}
