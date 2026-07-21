<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OAuthExchangeRequest
{
    public function __construct(
        // Login codes are 64 hex chars (32 random bytes), exactly as the
        // action tokens in App\Dto\Auth\VerifyEmailRequest are. The cap is
        // deliberately slack rather than exact for the same reason: it bounds
        // what reaches hash() and the cache without breaking if the code format
        // ever widens.
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $code = '',
    ) {
    }
}
