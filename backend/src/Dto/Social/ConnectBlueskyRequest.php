<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Social\Model\SocialCredentialsModel;
use App\Service\Social\Social\Support\BlueskyCredentials;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConnectBlueskyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $handle,
        #[Assert\NotBlank]
        public string $appPassword,
    ) {
    }

    public function toCredentials(): SocialCredentialsModel
    {
        return BlueskyCredentials::buildCredentials($this->handle, $this->appPassword);
    }
}
