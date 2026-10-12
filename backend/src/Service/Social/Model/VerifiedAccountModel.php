<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class VerifiedAccountModel
{
    public function __construct(
        public string $identity,
        public string $handle,
        public string $serviceUrl,
    ) {
    }
}
