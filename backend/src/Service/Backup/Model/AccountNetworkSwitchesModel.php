<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

use App\Enum\SocialNetwork;

final readonly class AccountNetworkSwitchesModel
{
    /**
     * @param list<SocialNetwork> $savedSearchIncluded
     * @param list<SocialNetwork> $forYouExcluded
     */
    public function __construct(
        public array $savedSearchIncluded,
        public array $forYouExcluded,
    ) {
    }
}
