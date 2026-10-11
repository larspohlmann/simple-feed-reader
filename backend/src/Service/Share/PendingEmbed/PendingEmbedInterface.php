<?php

declare(strict_types=1);

namespace App\Service\Share\PendingEmbed;

use App\Entity\Entry;

/** A post whose platform answers its embed after ingest, so until then it shares a link of its own. */
interface PendingEmbedInterface
{
    public function awaitsEmbed(Entry $entry): bool;
}
