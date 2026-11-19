<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

/** The shapes a Mastodon list id and tag name take, so neither can steer the path it is put into. */
final class MastodonFeedIds
{
    private const string LIST_ID = '/^\d{1,32}$/D';
    private const string TAG_NAME = '/^[\p{L}\p{M}\p{N}_\x{00B7}\x{200C}]{1,100}$/uD';

    public static function isListId(string $listId): bool
    {
        return 1 === preg_match(self::LIST_ID, $listId);
    }

    public static function isTagName(string $tagName): bool
    {
        return 1 === preg_match(self::TAG_NAME, $tagName);
    }

    private function __construct()
    {
    }
}
