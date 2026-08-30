<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class EmbeddedImage
{
    public function __construct(
        public string $cid,
        public string $bytes,
        public string $contentType,
    ) {
    }
}
