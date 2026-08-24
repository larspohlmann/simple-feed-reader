<?php

declare(strict_types=1);

namespace App\Dto\SavedSearch;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateSavedSearchRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 100)]
        public string $term = '',
        public bool $wholeWord = false,
    ) {
    }
}
