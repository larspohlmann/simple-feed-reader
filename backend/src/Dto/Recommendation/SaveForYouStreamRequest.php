<?php

declare(strict_types=1);

namespace App\Dto\Recommendation;

use App\Entity\ForYouStreamTuning;
use App\Enum\ForYouStreamPrecision;

final readonly class SaveForYouStreamRequest
{
    public function __construct(public bool $enabled, public ForYouStreamPrecision $precision)
    {
    }

    public function toTuning(): ForYouStreamTuning
    {
        return new ForYouStreamTuning($this->enabled, $this->precision);
    }
}
