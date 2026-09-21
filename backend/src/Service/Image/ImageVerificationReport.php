<?php

declare(strict_types=1);

namespace App\Service\Image;

final readonly class ImageVerificationReport
{
    public function __construct(
        public int $measured,
        public int $kept,
        public int $dropped,
        public int $retried,
    ) {
    }

    /**
     * @return array{measured: int, kept: int, dropped: int, retried: int}
     */
    public function toArray(): array
    {
        return [
            'measured' => $this->measured,
            'kept' => $this->kept,
            'dropped' => $this->dropped,
            'retried' => $this->retried,
        ];
    }
}
