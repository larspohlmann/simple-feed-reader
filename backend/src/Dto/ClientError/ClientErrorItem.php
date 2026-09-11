<?php

declare(strict_types=1);

namespace App\Dto\ClientError;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ClientErrorItem
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 2000)]
        public string $message = '',
        #[Assert\Length(max: 8000)]
        public ?string $stack = null,
        #[Assert\Length(max: 200)]
        public ?string $kind = null,
        #[Assert\Length(max: 2000)]
        public ?string $url = null,
        #[Assert\Length(max: 500)]
        public ?string $route = null,
        #[Assert\Length(max: 200)]
        public ?string $buildVersion = null,
        #[Assert\Length(max: 500)]
        public ?string $userAgent = null,
        #[Assert\Length(max: 40)]
        public ?string $at = null,
    ) {
    }
}
