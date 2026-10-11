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
    private const string TAG_AUTHORITY = '#^tag:([^,]+),#i';

    public static function of(Entry $entry): ?string
    {
        $target = $entry->getUrl();
        $siteUrl = $entry->getFeed()->getSiteUrl();
        if ($target === null || $siteUrl === null) {
            return null;
        }

        $siteHost = HostKey::forUrl($siteUrl);

        return in_array($siteHost, self::homeHosts($entry), true) && HostKey::forUrl($target) !== $siteHost
            ? $target
            : null;
    }

    /** @return list<string> */
    private static function homeHosts(Entry $entry): array
    {
        $hosts = [];
        $discussionUrl = $entry->getDiscussion()->url;
        if ($discussionUrl !== null) {
            $hosts[] = HostKey::forUrl($discussionUrl);
        }

        $guid = $entry->getGuid();
        if (AbsoluteHttpUrl::matches($guid)) {
            $hosts[] = HostKey::forUrl($guid);
        } elseif (preg_match(self::TAG_AUTHORITY, $guid, $matches) === 1) {
            $hosts[] = HostKey::forUrl('//' . $matches[1]);
        }

        return $hosts;
    }

    private function __construct()
    {
    }
}
