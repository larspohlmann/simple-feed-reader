<?php

declare(strict_types=1);

namespace App\Service\Backup\Model;

use App\Enum\InclusionSurface;
use App\Enum\SocialNetwork;

final readonly class AccountNetworkSwitchesModel
{
    /**
     * @param list<SocialNetwork>                $savedSearchIncludedNetworks
     * @param array<string, list<SocialNetwork>> $excludedNetworks            by InclusionSurface value
     */
    public function __construct(
        public array $savedSearchIncludedNetworks,
        public array $excludedNetworks,
    ) {
    }

    /** @return list<SocialNetwork> */
    public function excludedFrom(InclusionSurface $surface): array
    {
        return $this->excludedNetworks[$surface->value] ?? [];
    }
}
