<?php

declare(strict_types=1);

namespace App\Service\Discovery\ShareLinkFeed;

/**
 * Maps any page of a GitHub repository to the repository's releases feed, which GitHub fills from the tags when the
 * repository publishes no releases. The repository page itself advertises no feed.
 */
final readonly class GitHubRepositoryFeed implements ShareLinkFeedInterface
{
    private const array HOSTS = ['github.com', 'www.github.com'];

    /** `/<owner>/<repository>`, then anything; a `.git` suffix is the clone address of the same repository. */
    private const string REPOSITORY_PATH = '#^/([A-Za-z0-9][A-Za-z0-9-]*)/([A-Za-z0-9._-]+?)(?:\.git)?(?:/|$)#';

    private const string RELEASES_FEED = 'https://github.com/%s/%s/releases.atom';

    public function feedUrl(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);
        if (
            !\in_array($host, self::HOSTS, true)
            || str_ends_with($path, '.atom')
            || 1 !== preg_match(self::REPOSITORY_PATH, $path, $match)
        ) {
            return null;
        }

        return sprintf(self::RELEASES_FEED, $match[1], $match[2]);
    }
}
