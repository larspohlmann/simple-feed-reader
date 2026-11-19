<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Model;

final readonly class BlueskySessionModel
{
    public function __construct(
        public string $accessJwt,
        public string $handle,
    ) {
    }
}
