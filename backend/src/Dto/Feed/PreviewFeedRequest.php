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
         * 'scraped' extracts the page's article list. Not an enum on purpose: any other value, known or not, takes
         * the feed-document path instead of failing validation.
         */
        #[Assert\Length(max: 20)]
        public ?string $format = null,
    ) {
    }
}
