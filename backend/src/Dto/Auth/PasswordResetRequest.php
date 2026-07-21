<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PasswordResetRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        // Matches App\Entity\User::$email (VARCHAR 180). Nothing is written on
        // this path, but the bound keeps the lookup and any future write honest
        // across SQLite and MySQL alike.
        #[Assert\Length(max: 180)]
        public string $email = '',
        #[Assert\NotBlank(message: 'Complete the anti-spam challenge.')]
        public string $altcha = '',
    ) {
    }
}
