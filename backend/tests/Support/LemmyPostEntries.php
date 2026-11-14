<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Discussion;
use App\Enum\CommentsLoad;

/** An entry for a Lemmy post, which the caller persists when its test needs a row. */
final class LemmyPostEntries
{
    public const string PAGE = 'https://lemmy.example/post/42';

    private function __construct()
    {
    }

    public static function on(Feed $feed, string $page = self::PAGE, ?string $guid = null): Entry
    {
        $published = new \DateTimeImmutable('2026-11-14T00:00:00Z');
        $entry = new Entry($feed, $guid ?? $page, null, 'A post title', $published, $published);
        $entry->setDiscussion(Discussion::of($page, null, CommentsLoad::Auto));

        return $entry;
    }
}
