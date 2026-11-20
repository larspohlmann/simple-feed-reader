<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Support;

final class LemmyCommentPath
{
    public static function extractParentId(?string $path): ?string
    {
        $segments = explode('.', $path ?? '');
        if (count($segments) < 3) {
            return null;
        }
        $parent = $segments[count($segments) - 2];

        return ctype_digit($parent) ? $parent : null;
    }

    private function __construct()
    {
    }
}
