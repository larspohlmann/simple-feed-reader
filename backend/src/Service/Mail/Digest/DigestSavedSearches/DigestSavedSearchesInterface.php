<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestSavedSearches;

use App\Entity\SavedSearch;

interface DigestSavedSearchesInterface
{
    /** @return list<SavedSearch> */
    public function findIncludedInDigestForUser(int $userId): array;
}
