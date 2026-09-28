<?php

declare(strict_types=1);

namespace App\Service\Tag\Model;

final readonly class TagDetailsModel
{
    public function __construct(
        public string $name,
        public ?string $color = null,
        public ?string $icon = null,
    ) {
    }
}
