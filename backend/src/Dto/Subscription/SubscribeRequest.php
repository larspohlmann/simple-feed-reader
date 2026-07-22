<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubscribeRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(protocols: ['http', 'https'])]
        #[Assert\Length(max: 750)]
        public string $url = '',
    ) {
    }
}
