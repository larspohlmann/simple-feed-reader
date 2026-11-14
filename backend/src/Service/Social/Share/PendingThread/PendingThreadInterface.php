<?php

declare(strict_types=1);

namespace App\Service\Share\PendingThread;

use App\Entity\Entry;

/** A post whose author's own replies are folded into it after ingest. */
interface PendingThreadInterface
{
    public function awaitsThread(Entry $entry): bool;
}
