<?php

declare(strict_types=1);

namespace App\Dto\SavedSearch;

use App\Service\Search\SavedSearchDefinition;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateSavedSearchRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 100)]
        public string $term = '',
        public bool $wholeWord = false,
        public bool $phrase = false,
    ) {
    }

    public function toDefinition(): SavedSearchDefinition
    {
        return new SavedSearchDefinition(term: $this->term, wholeWord: $this->wholeWord, phrase: $this->phrase);
    }
}
