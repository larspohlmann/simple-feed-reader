<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Enum\Handedness;

final readonly class UpdateHandednessRequest
{
    public function __construct(
        public Handedness $handedness,
    ) {
    }
}
