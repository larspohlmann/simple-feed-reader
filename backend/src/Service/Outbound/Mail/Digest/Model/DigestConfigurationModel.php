<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Model;

use App\Enum\DigestCadence;
use App\Enum\DigestFormat;

final readonly class DigestConfigurationModel
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
