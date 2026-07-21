<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class VerifyEmailRequest
{
    public function __construct(
        // Tokens are 64 hex chars (32 random bytes). The cap is deliberately
        // slack rather than exact, so it rejects abuse without breaking if the
        // token format ever widens - but it does bound what reaches the
        // service, and therefore what reaches hash() and the database.
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $token = '',
    ) {
    }
}
