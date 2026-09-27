<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * The secrets of one in-flight sign-in. `$codeChallenge` is recomputed from `$codeVerifier` on both legs, so the two
 * always match; `$browserToken` is set only by start() and binds the flow to the browser that began it.
 */
final readonly class OAuthStartState
{
    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $codeChallenge,
        public ?string $browserToken = null,
    ) {
    }
}
