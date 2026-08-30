<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * Moves the public change marker an open reader polls before it asks the API
 * for fresh counts. A moved marker means "an import stored new content"; an
 * unchanged one lets the tick stop before any PHP request (#720).
 */
interface ContentChangeMarkerInterface
{
    public function markChanged(): void;
}
