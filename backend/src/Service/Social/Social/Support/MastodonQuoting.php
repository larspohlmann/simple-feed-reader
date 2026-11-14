<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;

/** Mastodon 4.5+ tells, per status, whether the asking user may quote it; an older instance tells nothing. */
final class MastodonQuoting
{
    /** `unknown` is a policy Mastodon cannot judge, which its docs advise treating as denied. */
    private const array ALLOWED = ['automatic', 'manual'];

    private function __construct()
    {
    }

    public static function allows(JsonNodeModel $status): bool
    {
        return \in_array($status->node('quote_approval')->string('current_user'), self::ALLOWED, true);
    }
}
