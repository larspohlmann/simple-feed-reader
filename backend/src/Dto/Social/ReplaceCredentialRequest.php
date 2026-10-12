<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Model\SocialCredentialsModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReplaceCredentialRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $secret,
    ) {
    }

    public function toCredentials(SocialAccount $account): SocialCredentialsModel
    {
        return SocialCredentialsModel::forReconnect($account, $this->secret);
    }
}
