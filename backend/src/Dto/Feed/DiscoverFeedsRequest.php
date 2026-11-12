<?php

declare(strict_types=1);

namespace App\Dto\Feed;

use App\Service\Url\Support\EnteredUrl;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DiscoverFeedsRequest
{
    #[Assert\NotBlank]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 750)]
    public string $url;

    public function __construct(string $url = '')
    {
        $this->url = EnteredUrl::withScheme($url);
    }
}
