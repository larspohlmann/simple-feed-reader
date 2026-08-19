<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * Maps a Substack profile-share URL onto the feed it stands for.
 *
 * "Copy link to profile" hands the user `https://substack.com/@handle?…utm…`.
 * That page carries no autodiscovery link and is rendered client-side, and the
 * feed lives on a different host entirely — `https://handle.substack.com/feed`.
 * Same-origin path guessing (WellKnownFeedProbe) can never cross to that host,
 * so discovery dead-ends on a URL whose feed is one hostname away. This spells
 * that hostname out from the handle.
 *
 * The guess is never trusted on its own: discovery fetches and PARSES whatever
 * this returns, and a handle whose subdomain does not exist is bounced by
 * Substack straight back to the profile HTML — which fails to parse, so
 * discovery falls through exactly as if this had said nothing. The rewrite can
 * therefore only ever turn a dead end into a subscription, never a good URL
 * into a wrong one.
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

    /**
     * The publication feed a profile URL points at, or null when the URL is not
     * a bare Substack profile — in which case discovery proceeds with the URL
     * untouched.
     */
    public function feedUrl(string $enteredUrl): ?string
    {
        $host = strtolower((string) parse_url($enteredUrl, PHP_URL_HOST));
        if (!\in_array($host, self::PROFILE_HOSTS, true)) {
            return null;
        }

        $path = (string) parse_url($enteredUrl, PHP_URL_PATH);
        if (1 !== preg_match('#^/@([A-Za-z0-9_-]+)/?$#', $path, $handle)) {
            return null;
        }

        return sprintf('https://%s.substack.com/feed', strtolower($handle[1]));
    }
}
