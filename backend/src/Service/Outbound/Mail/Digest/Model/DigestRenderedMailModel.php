<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Model;

final readonly class DigestRenderedMailModel
{
    public function __construct(
        public string $subject,
        public string $body,
    ) {
    }
}
