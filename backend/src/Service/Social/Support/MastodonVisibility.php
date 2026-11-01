<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;

/** Mastodon boosts only public and unlisted statuses; a status that names no visibility is taken as public. */
final class MastodonVisibility
{
    private const array REBLOGGABLE = ['public', 'unlisted'];

    private function __construct()
    {
    }

    public static function allowsReblog(JsonNodeModel $status): bool
    {
        return \in_array($status->string('visibility') ?? 'public', self::REBLOGGABLE, true);
    }
}
