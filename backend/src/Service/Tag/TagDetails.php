<?php

declare(strict_types=1);

namespace App\Service\Tag;

final readonly class TagDetails
{
    public function __construct(
        public string $name,
        public ?string $color = null,
        public ?string $icon = null,
    ) {
    }
}
