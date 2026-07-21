<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PasswordResetConfirmRequest
{
    public function __construct(
        // Tokens are 64 hex chars; see VerifyEmailRequest for why the cap is
        // slack rather than exact.
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $token = '',
        // Same rule as registration. A weaker bound here would make reset a
        // downgrade path around the length requirement.
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
    ) {
    }
}
