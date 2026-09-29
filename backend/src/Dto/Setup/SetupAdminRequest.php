<?php

declare(strict_types=1);

namespace App\Dto\Setup;

use App\Service\Auth\Support\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SetupAdminRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: PasswordPolicy::MINIMUM_LENGTH, max: PasswordPolicy::MAXIMUM_LENGTH)]
        public string $password = '',
        #[Assert\NotBlank]
        public string $secret = '',
    ) {
    }
}
