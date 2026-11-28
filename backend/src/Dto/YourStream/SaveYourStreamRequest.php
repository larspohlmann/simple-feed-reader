<?php

declare(strict_types=1);

namespace App\Dto\YourStream;

use App\Entity\YourStreamTuning;
use App\Enum\YourStreamPrecision;

final readonly class SaveYourStreamRequest
{
    public function __construct(public bool $enabled, public YourStreamPrecision $precision)
    {
    }

    public function toTuning(): YourStreamTuning
    {
        return new YourStreamTuning($this->enabled, $this->precision);
    }
}
