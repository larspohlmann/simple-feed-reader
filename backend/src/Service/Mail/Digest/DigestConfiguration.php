<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestConfiguration
{
    public function __construct(
        public bool $enabled,
        public DigestCadence $cadence,
        public int $sendHour,
        public int $weekday,
        public DigestFormat $format,
    ) {
    }
}
