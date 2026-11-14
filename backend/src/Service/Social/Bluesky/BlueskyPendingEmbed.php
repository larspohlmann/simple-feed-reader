<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\Entry;
use App\Enum\SourceFormat;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Share\PendingEmbed\PendingEmbedInterface;

final readonly class BlueskyPendingEmbed implements PendingEmbedInterface
{
    public function awaitsEmbed(Entry $entry): bool
    {
        return AtPostUri::matches($entry->getGuid())
            && SourceFormat::BLUESKY_API !== $entry->getFeed()->getSourceFormat();
    }
}
