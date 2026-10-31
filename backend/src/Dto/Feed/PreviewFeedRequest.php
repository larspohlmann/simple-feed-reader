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
         * A SourceFormat::VERBATIM value is previewed as that format; any other value is read as a feed document.
         * Not an enum on purpose, so an unknown value never fails validation.
         */
        #[Assert\Length(max: 20)]
        public ?string $format = null,
    ) {
    }
}
