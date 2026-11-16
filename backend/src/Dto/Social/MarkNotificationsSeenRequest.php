<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Enum\SocialNetwork;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarkNotificationsSeenRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?SocialNetwork $network = null,
        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $upTo = null,
    ) {
    }

    public function toNetwork(): SocialNetwork
    {
        return $this->network ?? throw new \LogicException('Validation guarantees a network.');
    }

    public function toUpTo(): int
    {
        return $this->upTo ?? throw new \LogicException('Validation guarantees an upTo.');
    }
}
