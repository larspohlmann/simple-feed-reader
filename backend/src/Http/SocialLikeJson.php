<?php

declare(strict_types=1);

namespace App\Http;

final class SocialLikeJson
{
    /** @return array{isLiked: bool} */
    public static function of(bool $isLiked): array
    {
        return ['isLiked' => $isLiked];
    }
}
