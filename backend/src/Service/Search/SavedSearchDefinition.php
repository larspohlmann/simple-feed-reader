<?php

declare(strict_types=1);

namespace App\Service\Search;

final readonly class SavedSearchDefinition
{
    public function __construct(
        public string $term,
        public bool $wholeWord = false,
        public bool $phrase = false,
    ) {
    }
}
