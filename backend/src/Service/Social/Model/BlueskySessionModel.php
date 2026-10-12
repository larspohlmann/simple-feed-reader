<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class BlueskySessionModel
{
    public function __construct(
        public string $accessJwt,
        public string $handle,
        public string $pdsUrl,
    ) {
    }
}
