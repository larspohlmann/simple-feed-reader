<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkReadRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['all', 'feed', 'tag'])]
        public string $scope,
        public \DateTimeImmutable $until,
        #[Assert\Positive]
        public ?int $id = null,
    ) {
    }
}
