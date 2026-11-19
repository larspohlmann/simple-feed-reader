<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Support;

use App\Service\Social\Lemmy\Model\LemmyListing;

final class LemmySearchPath
{
    public static function buildPath(string $query): string
    {
        return '/api/v3/search?q=' . rawurlencode($query)
            . '&type_=Posts&sort=New&listing_type=All&limit=' . LemmyListing::PAGE_SIZE;
    }

    private function __construct()
    {
    }
}
