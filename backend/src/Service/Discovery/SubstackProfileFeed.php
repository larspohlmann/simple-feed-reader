<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;

/**
 * Maps a Substack profile-share URL onto the feed it stands for.
 *
 * "Copy link to profile" hands the user `https://substack.com/@handle?…utm…`.
 * That page carries no autodiscovery link and is rendered client-side, and the
 * feed lives on a different host entirely — `https://<subdomain>.substack.com/feed`.
 * Same-origin path guessing (WellKnownFeedProbe) can never cross to that host,
 * so discovery dead-ends on a URL whose feed is one hostname away. This finds
 * that host.
 *
 * The handle is NOT the subdomain. `@abbeyheffer` publishes at
 * `theopenbookshelf.substack.com`, and for a meaningful fraction of authors the
 * two differ — so the subdomain is read from Substack's public-profile API
 * (`primaryPublication.subdomain`), not spelled out from the handle. The lookup
 * goes through the same SSRF-guarded fetcher as every other fetch, and the
 * subdomain feed resolves even for custom-domain publications: Substack
 * redirects it to the custom domain and the fetcher follows.
 *
 * The result is never trusted on its own — discovery fetches and PARSES it, and
 * anything that is not a feed falls through — and the lookup only ever ADDS a
 * subscription: every failure (a profile with no publication, an unreadable or
 * unreachable API, a subdomain that is not a bare label) returns null, and
 * discovery proceeds with the entered URL untouched, exactly as if this had
 * said nothing.
 *
 * Planned refactoring: this is deliberately one platform-specific class, wired
 * as a single line at the top of FeedDiscovery::discover(). The moment a SECOND
 * platform needs a host-level URL rewrite before discovery (Medium, Bluesky,
 * …), do not add a second one-off — extract a small keyed rule interface
 * (`feedUrl(string): ?string` per rule) that discovery consults, and move
 * Substack into the first rule. Generalize on the second case, not the third.
 */
final readonly class SubstackProfileFeed
{
    /** The hosts a profile-share URL is served from. */
    private const array PROFILE_HOSTS = ['substack.com', 'www.substack.com'];

    /** Substack's public-profile API; `%s` is the profile handle. */
    private const string PUBLIC_PROFILE_API = 'https://substack.com/api/v1/user/%s/public_profile';

    /** A publication subdomain is a bare DNS label — no dot, no slash, no host of its own. */
    private const string BARE_LABEL = '#^[A-Za-z0-9_-]+$#';

    public function __construct(private FeedFetcherInterface $fetcher)
    {
    }

    /**
     * The publication feed a profile URL points at, or null when the URL is not
     * a bare Substack profile or its publication cannot be resolved — in which
     * case discovery proceeds with the entered URL untouched.
     */
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

    /**
     * The subdomain of the handle's primary publication per Substack's
     * public-profile API, or null when the profile has none or the API cannot
     * be read. An unreachable or refusing API is a null, never an exception:
     * the caller only ever gains a subscription from a resolved subdomain.
     */
    private function primaryPublicationSubdomain(string $handle): ?string
    {
        try {
            $response = $this->fetcher->fetch(sprintf(self::PUBLIC_PROFILE_API, $handle));
        } catch (FetchException) {
            return null;
        }

        return $this->subdomainOf($response->body ?? '');
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
