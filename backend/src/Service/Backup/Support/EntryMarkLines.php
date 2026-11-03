<?php

declare(strict_types=1);

namespace App\Service\Backup\Support;

use App\Entity\EntryShare;
use App\Entity\EntryState;

/** The reader's own lines about an entry, its state and its share, each naming the entry by feed and guidHash. */
final class EntryMarkLines
{
    public static function state(EntryState $state, string $feedUrl): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY_STATE,
            'feedUrl' => $feedUrl,
            'guidHash' => $state->getEntry()->getGuidHash(),
            'isHidden' => $state->isHidden(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'hiddenAt' => BackupLineFormat::dateOrNull($state->getHiddenAt()),
            'isViewed' => $state->isViewed(),
            'viewedAt' => BackupLineFormat::dateOrNull($state->getViewedAt()),
        ]);
    }

    public static function share(EntryShare $share, string $feedUrl): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY_SHARE,
            'feedUrl' => $feedUrl,
            'guidHash' => $share->getEntry()->getGuidHash(),
            'sharedAt' => BackupLineFormat::date($share->getSharedAt()),
        ]);
    }

    private function __construct()
    {
    }
}
