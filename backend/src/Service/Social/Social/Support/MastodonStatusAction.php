<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

/** The path of a status of the account's own instance, `/api/v1/statuses/<id>`, and of an action on it. */
final class MastodonStatusAction
{
    private function __construct()
    {
    }

    public static function statusPath(string $statusId): string
    {
        return '/api/v1/statuses/' . rawurlencode($statusId);
    }

    public static function path(string $statusId, string $action): string
    {
        return self::statusPath($statusId) . '/' . $action;
    }
}
