<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Model;

use App\Repository\EntryListRow;

/**
 * One saved search's contribution to a digest: the rows to render, capped at DigestEntryFinder::PER_SEARCH, plus the
 * un-capped match count so the digest can say "+N more" and total the subject line correctly.
 *
 * @psalm-immutable
 */
final readonly class DigestSearchMatchesModel
{
    /** @param list<EntryListRow> $entries */
    public function __construct(
        public array $entries,
        public int $totalCount,
    ) {
    }
}
