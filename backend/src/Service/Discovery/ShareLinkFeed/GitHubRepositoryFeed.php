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
    private const string REPOSITORY_PATH =
        '#^/([A-Za-z0-9][A-Za-z0-9-]*)/(?!\.\.?(?:/|$))([A-Za-z0-9._-]+?)(?:\.git)?(?:/|$)#';

    /** GitHub's own top-level routes, which no account may take as its name. */
    private const array RESERVED_OWNERS = [
        'orgs', 'users', 'settings', 'topics', 'marketplace', 'sponsors', 'collections', 'explore', 'trending',
        'features', 'enterprise', 'notifications', 'search', 'login', 'pricing', 'about', 'apps', 'codespaces',
        'security', 'site', 'contact', 'customer-stories', 'events', 'new', 'pulls', 'issues', 'dashboard',
        'account', 'stars', 'watching', 'readme', 'team', 'join', 'signup',
    ];

    private const string RELEASES_FEED = 'https://github.com/%s/%s/releases.atom';

    public function feedUrl(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);
        if (!\in_array($host, self::HOSTS, true) || str_ends_with($path, '.atom')) {
            return null;
        }

        if (1 !== preg_match(self::REPOSITORY_PATH, $path, $match)) {
            return null;
        }

        return \in_array(strtolower($match[1]), self::RESERVED_OWNERS, true)
            ? null
            : sprintf(self::RELEASES_FEED, $match[1], $match[2]);
    }
}
