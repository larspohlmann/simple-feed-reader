<?php

declare(strict_types=1);

namespace App\Entity;

/** An entry's read flag and read instant exactly as a backup file carries them, legacy pairs included. */
final readonly class BackedUpReadMark
{
    public function __construct(
        public bool $isHidden,
        public ?\DateTimeImmutable $hiddenAt,
    ) {
    }
}
