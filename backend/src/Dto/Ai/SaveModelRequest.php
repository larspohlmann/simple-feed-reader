<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SaveModelRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $model,
    ) {
    }
}
