<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

use App\Enum\SocialNetwork;

final readonly class AccountNetworkSwitchesModel
{
    /**
     * @param list<SocialNetwork> $savedSearchIncludedNetworks
     * @param list<SocialNetwork> $forYouExcludedNetworks
     */
    public function __construct(
        public array $savedSearchIncludedNetworks,
        public array $forYouExcludedNetworks,
    ) {
    }
}
