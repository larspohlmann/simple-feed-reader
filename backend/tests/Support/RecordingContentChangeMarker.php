<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Refresh\ContentChangeMarker\ContentChangeMarkerInterface;

/** Counts how often the refresh moved the change marker, without touching disk. */
final class RecordingContentChangeMarker implements ContentChangeMarkerInterface
{
    public int $marks = 0;

    public function markChanged(): void
    {
        ++$this->marks;
    }
}
