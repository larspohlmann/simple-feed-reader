<?php

declare(strict_types=1);

namespace App\Http;

final class SocialLikeJson
{
    /** @return array{isLiked: true} */
    public static function liked(): array
    {
        return ['isLiked' => true];
    }

    /** @return array{isLiked: false} */
    public static function unliked(): array
    {
        return ['isLiked' => false];
    }
}
