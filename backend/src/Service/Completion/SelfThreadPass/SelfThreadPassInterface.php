<?php

declare(strict_types=1);

namespace App\Service\Completion\SelfThreadPass;

use App\Entity\ShareEnrichment;

interface SelfThreadPassInterface
{
    /** @param list<ShareEnrichment> $queued self-thread rows */
    public function complete(array $queued): void;
}
