<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubscribeRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
        #[Assert\Length(max: 750)]
        public string $url = '',
        /**
         * Candidate format the client picked ('scraped' subscribes the page
         * itself, skipping re-discovery). Deliberately not an enum choice:
         * unknown values simply take the default discovery path, so old
         * clients and new formats never hard-fail validation.
         */
        #[Assert\Length(max: 20)]
        public ?string $format = null,
    ) {
    }
}
