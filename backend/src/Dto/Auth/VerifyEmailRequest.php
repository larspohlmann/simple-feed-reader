<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class VerifyEmailRequest
{
    public function __construct(
        // Tokens are 64 hex characters. The cap is slack on purpose: a wider format still fits, and what reaches
        // hash() and the database stays bounded.
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $token = '',
    ) {
    }
}
