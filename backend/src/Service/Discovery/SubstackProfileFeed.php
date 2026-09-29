<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;

/**
 * Maps a `substack.com/@handle` profile-share URL to its publication's feed. The handle is not the subdomain, so the
 * subdomain comes from Substack's public-profile API. Every failure is a null, and discovery still parses the result,
 * so this can only ever add a subscription.
 */
final readonly class SubstackProfileFeed
{
    private const array PROFILE_HOSTS = ['substack.com', 'www.substack.com'];

    /** Substack's public-profile API; `%s` is the profile handle. */
    private const string PUBLIC_PROFILE_API = 'https://substack.com/api/v1/user/%s/public_profile';

    /** A publication subdomain is a bare DNS label — no dot, no slash, no host of its own. */
    private const string BARE_LABEL = '#^[A-Za-z0-9_-]+$#';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    /** The publication feed a profile URL points at, or null when it is not one or cannot be resolved. */
    public function feedUrl(string $enteredUrl): ?string
    {
        $handle = $this->profileHandle($enteredUrl);
        if (null === $handle) {
            return null;
        }

        $subdomain = $this->primaryPublicationSubdomain($handle);

        return null === $subdomain ? null : sprintf('https://%s.substack.com/feed', $subdomain);
    }

    /** The handle of a `substack.com/@handle` share URL, lowercased, or null for anything else. */
    private function profileHandle(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        if (!\in_array($host, self::PROFILE_HOSTS, true)) {
            return null;
        }

        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);

        return 1 === preg_match('#^/@([A-Za-z0-9_-]+)/?$#', $path, $handle)
            ? strtolower($handle[1])
            : null;
    }

    private function primaryPublicationSubdomain(string $handle): ?string
    {
        try {
            $response = $this->fetcher->fetch(sprintf(self::PUBLIC_PROFILE_API, $handle));
        } catch (FetchException) {
            return null;
        }

        return $this->subdomainOf($response->modifiedBody());
    }

    /** Reads and validates `primaryPublication.subdomain` out of a profile-API body. */
    private function subdomainOf(string $profileJson): ?string
    {
        $profile = json_decode($profileJson, true);
        $publication = \is_array($profile) ? ($profile['primaryPublication'] ?? null) : null;
        $subdomain = \is_array($publication) ? ($publication['subdomain'] ?? null) : null;

        return \is_string($subdomain) && 1 === preg_match(self::BARE_LABEL, $subdomain) ? $subdomain : null;
    }
}
