<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetSlowModelRequest
{
    public function __construct(
        #[Assert\NotNull]
        public bool $slowModel,
    ) {
    }
}
