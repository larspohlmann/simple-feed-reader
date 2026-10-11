<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Share\PendingEmbed\PendingEmbedInterface;

final readonly class BlueskyPendingEmbed implements PendingEmbedInterface
{
    public function awaitsEmbed(Entry $entry): bool
    {
        return AtPostUri::matches($entry->getGuid());
    }
}
