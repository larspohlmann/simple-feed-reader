<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Entity\Entry;
use App\Service\Ingest\Support\AtPostUri;

/** A post whose link cards come from its shared pages; a Bluesky post's card comes from the AppView instead. */
final class LinkPost
{
    public static function matches(Entry $entry): bool
    {
        return $entry->isTitleDerived() && !AtPostUri::matches($entry->getGuid());
    }

    private function __construct()
    {
    }
}
