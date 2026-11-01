<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;

/** Mastodon boosts only public and unlisted statuses; a status that names no visibility is taken as public. */
final class MastodonVisibility
{
    private const array REBLOGGABLE = ['public', 'unlisted'];
    private const array FROM_OPEN_TO_CLOSED = ['public', 'unlisted', 'private', 'direct'];

    private function __construct()
    {
    }

    public static function allowsReblog(JsonNodeModel $status): bool
    {
        return \in_array($status->string('visibility') ?? 'public', self::REBLOGGABLE, true);
    }

    /** An unknown visibility counts as public. */
    public static function stricterOf(string $left, string $right): string
    {
        return self::closedness($left) >= self::closedness($right) ? self::known($left) : self::known($right);
    }

    private static function closedness(string $visibility): int
    {
        return (int) array_search(self::known($visibility), self::FROM_OPEN_TO_CLOSED, true);
    }

    private static function known(string $visibility): string
    {
        return \in_array($visibility, self::FROM_OPEN_TO_CLOSED, true) ? $visibility : 'public';
    }
}
