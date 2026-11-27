<?php

declare(strict_types=1);

namespace App\Dto\Label;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LabelNameRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 60, normalizer: 'trim')]
        public string $name = '',
    ) {
    }

    public function toName(): string
    {
        return trim($this->name);
    }
}
