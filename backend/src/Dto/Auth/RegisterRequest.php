<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        // Not cosmetic: App\Entity\User::$email is VARCHAR(180). SQLite would
        // store an over-long address silently, MySQL strict mode would throw at
        // flush time as an unhandled 500. Validation is what keeps the two
        // backends behaving the same.
        #[Assert\Length(max: 180)]
        public string $email = '',
        // 12 chars with no composition rules: length beats character classes,
        // and the passphrase people actually remember is the one they keep.
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
        #[Assert\NotBlank(message: 'Complete the anti-spam challenge.')]
        public string $altcha = '',
    ) {
    }
}
