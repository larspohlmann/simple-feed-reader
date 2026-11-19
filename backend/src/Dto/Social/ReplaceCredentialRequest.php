<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Social\Model\CredentialReplacementModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReplaceCredentialRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        #[\SensitiveParameter] public string $secret,
        #[Assert\Regex('/^\d{6}$/')]
        public ?string $totpToken = null,
    ) {
    }

    public function toReplacement(): CredentialReplacementModel
    {
        return new CredentialReplacementModel($this->secret, $this->totpToken);
    }
}
