<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The join-order plans EntryPlanHintWalker can render onto an entry query's
 * outer SELECT, keyed to the entry table's own SQL alias (#1040, #1098).
 */
enum EntryPlanHint
{
    /** Drives the join from `entry`, for the chronological fan-in list (#1040). */
    case DateOrderedWalk;

    /** Also forces idx_entry_url_hash, for the per-page duplicate lookup (#1098). */
    case DuplicateLookup;

    public const string URL_HASH_INDEX_NAME = 'idx_entry_url_hash';

    public function optimizerHint(string $entryAlias): string
    {
        $joinPrefix = 'JOIN_PREFIX(' . $entryAlias . ')';

        return match ($this) {
            self::DateOrderedWalk => $joinPrefix,
            self::DuplicateLookup => $joinPrefix . ' INDEX(' . $entryAlias . ' ' . self::URL_HASH_INDEX_NAME . ')',
        };
    }
}
