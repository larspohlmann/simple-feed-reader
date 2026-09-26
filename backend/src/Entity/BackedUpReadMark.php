<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class BackedUpReadMark
{
    public function __construct(
        public bool $isHidden,
        public ?\DateTimeImmutable $hiddenAt,
    ) {
    }
}
