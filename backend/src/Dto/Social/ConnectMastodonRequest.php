<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Model\SocialCredentialsModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConnectMastodonRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $instanceUrl,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $accessToken,
    ) {
    }

    /** @throws InvalidInstanceUrlException */
    public function toCredentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::mastodon($this->instanceUrl, $this->accessToken);
    }
}
