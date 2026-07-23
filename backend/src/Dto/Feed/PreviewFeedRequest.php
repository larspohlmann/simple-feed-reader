<?php

declare(strict_types=1);

namespace App\Dto\Feed;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PreviewFeedRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
        #[Assert\Length(max: 750)]
        public string $url = '',
    ) {
    }
}
