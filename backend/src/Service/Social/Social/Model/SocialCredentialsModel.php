<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Enum\SocialNetwork;

final readonly class SocialCredentialsModel
{
    public function __construct(
        public SocialNetwork $network,
        public string $address,
        #[\SensitiveParameter] public string $secret,
        public string $hint,
        public string $login = '',
        public ?string $secondFactor = null,
    ) {
    }
}
