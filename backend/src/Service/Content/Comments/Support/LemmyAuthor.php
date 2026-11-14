<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Support;

use App\Service\Foundation\Url\Support\UrlOrigin;

final class LemmyAuthor
{
    private function __construct()
    {
    }

    /** A Lemmy person as a social account identity: the lower-cased `name@host` of their home instance. */
    public static function identity(?string $name, ?string $actorId): ?string
    {
        $handle = self::handle($name, $actorId);

        return null === $handle ? null : mb_strtolower(substr($handle, 1));
    }

    public static function handle(?string $name, ?string $actorId): ?string
    {
        $host = null === $actorId ? null : UrlOrigin::hostWithPort($actorId);

        return null === $name || null === $host ? null : '@' . $name . '@' . $host;
    }
}
