<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class HiddenProfileModel
{
    public function __construct(
        public string $did,
        public string $name,
    ) {
    }
}
