<?php

declare(strict_types=1);

namespace App\Dto\Setup;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetupAdminRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
        #[Assert\NotBlank]
        public string $secret = '',
    ) {
    }
}
