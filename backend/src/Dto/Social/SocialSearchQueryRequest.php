<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Support\SocialSearchQuery;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialSearchQueryRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: [SocialSearchQuery::class, 'normalized'])]
        #[Assert\Length(max: 512, normalizer: [SocialSearchQuery::class, 'normalized'])]
        public string $query = '',
    ) {
    }

    public function toQuery(): string
    {
        return SocialSearchQuery::normalized($this->query);
    }
}
