<?php

declare(strict_types=1);

namespace App\Service\Reader\RecipeFacts;

final readonly class RecipeFact
{
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }
}
