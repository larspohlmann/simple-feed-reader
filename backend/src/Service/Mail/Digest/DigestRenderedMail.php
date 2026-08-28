<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

final readonly class DigestRenderedMail
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {
    }
}
