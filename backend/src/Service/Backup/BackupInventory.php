<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\BackupHeader;

/**
 * What a backup file holds, without holding the file itself: the header and
 * account line, plus a count for every repeatable line kind. Produced by
 * BackupInspector's full pass over BackupReader, consumed by BackupFitCheck
 * before anything is deleted and by RestorePreviewer to describe the file to
 * the user.
 */
final readonly class BackupInventory
{
    public function __construct(
        public BackupHeader $header,
        public AccountLine $account,
        public int $tags,
        public int $feeds,
        public int $subscriptions,
        public int $entries,
        public int $entryStates,
    ) {
    }
}
