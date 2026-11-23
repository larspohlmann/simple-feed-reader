<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Enum\SocialNetwork;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RefreshNotificationsRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?SocialNetwork $network = null,
    ) {
    }

    public function toNetwork(): SocialNetwork
    {
        return $this->network ?? throw new \LogicException('Validation guarantees a network.');
    }
}
