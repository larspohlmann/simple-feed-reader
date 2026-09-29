<?php

declare(strict_types=1);

namespace App\Service\Search\Membership\SavedSearchMembershipWriter;

/** The one write the membership sweep makes: store the pairs a chunk matched. */
interface SavedSearchMembershipWriterInterface
{
    /**
     * @param array<int, list<int>> $entryIdsBySavedSearchId
     *
     * @return int the pairs that were not stored yet
     */
    public function insertMissing(array $entryIdsBySavedSearchId, \DateTimeImmutable $matchedAt): int;
}
