<?php

declare(strict_types=1);

namespace App\Service\Share\Support;

use App\Entity\Entry;
use App\Service\Fetch\Support\HostKey;
use App\Service\Url\Support\AbsoluteHttpUrl;

/**
 * The page a titled entry shares, none for a post: its url, when that leaves the feed's site while the entry itself
 * lives there. An opaque GUID proves no home, so a news site linking a sister brand is no share.
 */
final class TitledShareTarget
{
    private const string TAG_AUTHORITY = '#^tag:([^,]+),#i';

    public static function of(Entry $entry): ?string
    {
        if ($entry->isTitleDerived()) {
            return null;
        }
        $target = $entry->getUrl();
        $siteUrl = $entry->getFeed()->getSiteUrl();
        if ($target === null || $siteUrl === null) {
            return null;
        }

        $siteHost = HostKey::forUrl($siteUrl);
        if (self::sameSite(HostKey::forUrl($target), $siteHost)) {
            return null;
        }

        return array_any(
            self::homeHosts($entry),
            static fn (string $homeHost): bool => self::sameSite($homeHost, $siteHost),
        ) ? $target : null;
    }

    private static function sameSite(string $host, string $siteHost): bool
    {
        return $host === $siteHost
            || str_ends_with($host, '.' . $siteHost)
            || str_ends_with($siteHost, '.' . $host);
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
