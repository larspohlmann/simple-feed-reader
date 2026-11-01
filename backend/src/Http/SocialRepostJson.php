<?php

declare(strict_types=1);

namespace App\Http;

final class SocialRepostJson
{
    /** @return array{isReposted: bool} */
    public static function of(bool $isReposted): array
    {
        return ['isReposted' => $isReposted];
    }
}
