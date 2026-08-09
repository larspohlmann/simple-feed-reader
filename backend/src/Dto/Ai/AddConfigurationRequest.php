<?php

declare(strict_types=1);

namespace App\Dto\Ai;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddConfigurationRequest
{
    public function __construct(
        #[Assert\Length(max: 120)]
        public ?string $name,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $baseUrl,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 512)]
        public string $apiKey,
    ) {
    }
}
