<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;

/** Mastodon boosts only public and unlisted statuses; a status that names no visibility is taken as public. */
final class MastodonVisibility
{
    private const array REBLOGGABLE = ['public', 'unlisted'];
    /** Every visibility, from the most open to the most closed. */
    public const array ALL = ['public', 'unlisted', 'private', 'direct'];

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
        return (int) array_search(self::known($visibility), self::ALL, true);
    }

    private static function known(string $visibility): string
    {
        return \in_array($visibility, self::ALL, true) ? $visibility : 'public';
    }
}
