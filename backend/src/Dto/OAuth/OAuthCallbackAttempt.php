<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

/** What the provider's redirect brought back, as OAuthController::callback() read it off the request. */
final readonly class OAuthCallbackAttempt
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
