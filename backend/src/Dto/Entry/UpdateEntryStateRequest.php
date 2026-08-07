<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use Symfony\Component\Validator\Constraints as Assert;

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
        // One-way (#307): `viewed` can be set, never cleared. Constraints skip
        // null, so only an explicit false is rejected.
        #[Assert\IsTrue(message: 'isViewed is one-way and can only be set to true.')]
        public ?bool $isViewed = null,
    ) {
    }
}
