<?php

declare(strict_types=1);

namespace App\Service\OAuth\Model;

final readonly class OAuthCallbackAttemptModel
{
    public function __construct(
        public string $provider,
        public bool $declined,
        public ?string $state,
        public ?string $code,
        public ?string $browserToken,
    ) {
    }
}
