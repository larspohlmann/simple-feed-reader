<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestEntry
{
    public function __construct(
        public string $title,
        public string $feedName,
        public string $shortDescription,
        public string $url,
    ) {
    }
}
