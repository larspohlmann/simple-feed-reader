<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\BackupHeader;

/**
 * What a restore would do, before it does anything: the file's provenance,
 * what it would load, and what the account currently holds — so the UI can
 * show a before/after instead of asking the user to trust a black box.
 */
final readonly class RestorePreview
{
    public function __construct(
        public BackupHeader $header,
        public BackupInventory $toLoad,
        public int $currentSubscriptions,
        public int $currentTags,
        public int $currentEntryStates,
        public int $currentRecommendationRuns,
    ) {
    }
}
