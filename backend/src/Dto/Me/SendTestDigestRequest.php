<?php

declare(strict_types=1);

namespace App\Dto\Me;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendTestDigestRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: 30)]
        public int $days,
    ) {
    }
}
