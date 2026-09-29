<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class OAuthExchangeRequest
{
    public function __construct(
        // Login codes are 64 hex characters; the cap is slack on purpose, bounding what reaches hash() and the cache.
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $code = '',
    ) {
    }
}
