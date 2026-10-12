<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Model\SocialCredentialsModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConnectBlueskyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $handle,
        #[Assert\NotBlank]
        #[Assert\Regex(
            pattern: '/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/',
            message: 'Use an app password, not your account password.',
        )]
        public string $appPassword,
    ) {
    }

    public function toCredentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::bluesky($this->handle, $this->appPassword);
    }
}
