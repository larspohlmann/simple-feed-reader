<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\RecipeFacts\Model;

final readonly class RecipeFactModel
{
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }
}
