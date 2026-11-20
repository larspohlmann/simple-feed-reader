<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\SocialNetwork;

final class CanonicalKeyRuleEntries
{
    private function __construct()
    {
    }

    public static function buildEntry(string $guid, ?string $url, ?SocialNetwork $network): Entry
    {
        $feed = new Feed('https://example.com/feed');
        $feed->classifyAs($network);
        $now = new \DateTimeImmutable('2026-01-01');

        return new Entry($feed, $guid, $url, 'Title', $now, $now);
    }

    public static function buildDiscussionEntry(string $postUrl, ?SocialNetwork $network): Entry
    {
        $entry = self::buildEntry($postUrl, null, $network);
        $entry->setDiscussion(Discussion::platformThread($postUrl));

        return $entry;
    }
}
