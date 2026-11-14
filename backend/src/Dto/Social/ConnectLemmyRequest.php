<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Social\Model\SocialCredentialsModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConnectLemmyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $instanceUrl,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $username,
        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $password,
        #[Assert\Regex('/^\d{6}$/')]
        public ?string $totpToken = null,
    ) {
    }

    /** @throws InvalidInstanceUrlException */
    public function toCredentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::lemmy($this->instanceUrl, $this->username, $this->password, $this->totpToken);
    }
}
