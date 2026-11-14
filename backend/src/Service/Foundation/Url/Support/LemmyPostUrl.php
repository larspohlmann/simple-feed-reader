<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

use App\Service\Url\Model\LemmyPostPageModel;

final class LemmyPostUrl
{
    private const string POST_PAGE = '#^https://([a-z0-9.-]+)/post/(\d+)/?$#i';

    public static function parse(?string $url): ?LemmyPostPageModel
    {
        if ($url === null || preg_match(self::POST_PAGE, $url, $parts) !== 1) {
            return null;
        }

        return new LemmyPostPageModel(strtolower($parts[1]), (int) $parts[2]);
    }

    private function __construct()
    {
    }
}
