<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RenameConfigurationRequest
{
    public function __construct(
        #[Assert\Length(max: 120)]
        public ?string $name,
    ) {
    }
}
