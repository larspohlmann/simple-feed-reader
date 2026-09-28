<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

use App\Service\Backup\Model\RestoreSourceModel;

/**
 * What a restore would do, before it does anything: the file's provenance,
 * what it would load, and what the account currently holds — so the UI can
 * show a before/after instead of asking the user to trust a black box.
 */
final readonly class RestorePreviewModel
{
    public function __construct(
        public RestoreSourceModel $source,
        public BackupInventoryModel $toLoad,
        public int $currentSubscriptions,
        public int $currentTags,
        public int $currentEntryStates,
        public int $currentRecommendationRuns,
    ) {
    }
}
