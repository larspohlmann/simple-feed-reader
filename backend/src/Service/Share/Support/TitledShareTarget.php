<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Entity\Entry;
use App\Service\Fetch\Support\HostKey;
use App\Service\Url\Support\AbsoluteHttpUrl;

/**
 * The page a titled entry shares: its url, when that leaves the feed's site while the entry itself lives there. An
 * opaque GUID proves no home, so a news site linking a sister brand is no share.
 */
final class TitledShareTarget
{
    private const string TAG_AUTHORITY = '#^tag:(?:www\.)?([^,]+),#i';

    public static function of(Entry $entry): ?string
    {
        $target = $entry->getUrl();
        $siteUrl = $entry->getFeed()->getSiteUrl();
        if ($target === null || $siteUrl === null) {
            return null;
        }

        $siteHost = HostKey::forUrl($siteUrl);

        return self::homeHost($entry) === $siteHost && HostKey::forUrl($target) !== $siteHost ? $target : null;
    }

    private static function homeHost(Entry $entry): ?string
    {
        $discussionUrl = $entry->getDiscussion()->url;
        if ($discussionUrl !== null) {
            return HostKey::forUrl($discussionUrl);
        }
        $guid = $entry->getGuid();
        if (AbsoluteHttpUrl::matches($guid)) {
            return HostKey::forUrl($guid);
        }

        return preg_match(self::TAG_AUTHORITY, $guid, $matches) === 1 ? strtolower($matches[1]) : null;
    }

    private function __construct()
    {
    }
}
