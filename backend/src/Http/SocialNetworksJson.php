<?php

declare(strict_types=1);

namespace App\Http;

use App\Enum\SocialNetwork;

final class SocialNetworksJson
{
    /**
     * @param list<SocialNetwork> $networks
     *
     * @return list<string>
     */
    public static function values(array $networks): array
    {
        return array_map(static fn (SocialNetwork $network): string => $network->value, $networks);
    }
}
