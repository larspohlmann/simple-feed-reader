<?php

declare(strict_types=1);

namespace App\Dto\SavedSearch;

final readonly class UpdateSavedSearchRequest
{
    public function __construct(
        public bool $includeInDigest,
    ) {
    }
}
