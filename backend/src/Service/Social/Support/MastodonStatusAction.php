<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

/** The path of an action on a status of the account's own instance, `/api/v1/statuses/<id>/<action>`. */
final class MastodonStatusAction
{
    private function __construct()
    {
    }

    public static function path(string $statusId, string $action): string
    {
        return '/api/v1/statuses/' . rawurlencode($statusId) . '/' . $action;
    }
}
