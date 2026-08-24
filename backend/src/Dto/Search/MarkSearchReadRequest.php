<?php

declare(strict_types=1);

namespace App\Dto\Search;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkSearchReadRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $q,
        public \DateTimeImmutable $until,
    ) {
    }
}
