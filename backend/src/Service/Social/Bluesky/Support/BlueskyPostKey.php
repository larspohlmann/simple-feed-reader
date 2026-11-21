<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

use App\Enum\CanonicalItemKind;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Social\BlueskyPost\Support\AtPostUri;

final class BlueskyPostKey
{
    private function __construct()
    {
    }

    public static function parseKey(?string $uri): ?CanonicalKeyModel
    {
        if (null === $uri || !AtPostUri::matches($uri)) {
            return null;
        }

        return new CanonicalKeyModel(CanonicalItemKind::Bluesky, $uri);
    }
}
