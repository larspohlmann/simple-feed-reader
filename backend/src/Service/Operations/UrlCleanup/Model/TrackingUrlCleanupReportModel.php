<?php

declare(strict_types=1);

namespace App\Service\Operations\UrlCleanup\Model;

final readonly class TrackingUrlCleanupReportModel
{
    public function __construct(
        public int $entriesCleaned = 0,
        public int $hashesChanged = 0,
        public int $cardsCleaned = 0,
    ) {
    }

    #[\NoDiscard]
    public function plus(self $other): self
    {
        return new self(
            $this->entriesCleaned + $other->entriesCleaned,
            $this->hashesChanged + $other->hashesChanged,
            $this->cardsCleaned + $other->cardsCleaned,
        );
    }
}
