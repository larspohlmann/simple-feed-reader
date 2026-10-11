<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

/**
 * Maps a profile seen through another instance (`/@user@home.host`, any instance, any page under it) to the
 * account's feed on its home instance. The viewing instance sends a non-browser client to an interstitial that
 * links no feed, so that page is never fetched.
 */
final readonly class FediverseRemoteProfileFeed implements ShareLinkFeedInterface
{
    private const string REMOTE_PROFILE_PATH = '#^/@([A-Za-z0-9_.-]+)@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)(?:/|$)#';

    private const string PROFILE_FEED = 'https://%s/@%s.rss';

    public function feedUrl(string $enteredUrl): ?string
    {
        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);
        if (1 !== preg_match(self::REMOTE_PROFILE_PATH, $path, $match)) {
            return null;
        }

        return sprintf(self::PROFILE_FEED, strtolower($match[2]), $match[1]);
    }
}
