<?php

declare(strict_types=1);

namespace App\Dto\Label;

use App\Entity\Label;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LabelNameRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Label::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name = '',
    ) {
    }

    public function toName(): string
    {
        return trim($this->name);
    }
}
