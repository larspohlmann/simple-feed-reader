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
        /**
         * Candidate format being previewed ('scraped' extracts the page's
         * article list instead of parsing a feed document). Not an enum
         * choice on purpose — mirrors SubscribeRequest: unknown values fall
         * back to the feed-document path instead of hard-failing validation.
         */
        #[Assert\Length(max: 20)]
        public ?string $format = null,
    ) {
    }
}
