<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChooseProfileConnectionRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $connectionId,
    ) {
    }
}
