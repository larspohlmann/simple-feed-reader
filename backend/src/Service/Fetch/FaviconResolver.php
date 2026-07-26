<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Psr\Log\LoggerInterface;

/**
 * Best-effort favicon resolution for a batch of feeds' sites. Fetches each
 * site homepage concurrently through the SSRF-guarded batch fetcher, parses
 * its <link rel="...icon..."> tags and returns the largest https icon per
 * site, falling back to the /favicon.ico convention. Never throws: a favicon
 * is a nicety, so any failure degrades to the fallback (or null) rather than
 * disturbing the refresh that asked for it.
 *
 * Not `final`: the catalog favicon warmer and command tests double this
 * collaborator, and the codebase has no favicon-resolver interface to mock
 * instead. `readonly` still holds the immutability guarantee that matters.
 */
readonly class FaviconResolver
{
    private const int URL_MAX = 2048;

    public function __construct(
        private BatchFeedFetcherInterface $fetcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve a favicon for each site, fetching the homepages concurrently.
     *
     * @param array<int, string> $baseUrlsByFeedId a feed's siteUrl or, failing
     *                                             that, its feed URL
     *
     * @return array<int, string|null> an https URL per input key, or null when
     *                                 the URL carried no host to derive one from
     */
    public function resolveAll(array $baseUrlsByFeedId): array
    {
        $origins = [];
        $icons = [];
        foreach ($baseUrlsByFeedId as $feedId => $baseUrl) {
            $origin = self::httpsOrigin($baseUrl);
            if (null === $origin) {
                $icons[$feedId] = null;
                continue;
            }
            $origins[$feedId] = $origin;
        }

        return $icons + $this->fetchAllIcons($origins);
    }

    /**
     * Fetches every homepage in one batch and resolves each to an icon URL.
     * `BatchFeedFetcherInterface::fetchAll()` promises never to throw for an
     * individual site's outcome, but that promise does not cover an invariant
     * violation inside the fetcher itself (an exhausted queue pulled once too
     * often, a misconfigured concurrency, ...) — a bug there is still not
     * this best-effort component's business to propagate. Any site the batch
     * never got a turn to answer for still falls back to the /favicon.ico
     * convention rather than being silently dropped from the result.
     *
     * @param array<int, string> $origins
     *
     * @return array<int, string>
     */
    private function fetchAllIcons(array $origins): array
    {
        $icons = [];
        $tickets = array_map(static fn (string $origin): FetchTicket => new FetchTicket($origin), $origins);

        try {
            foreach ($this->fetcher->fetchAll($tickets) as $feedId => $outcome) {
                // fetchAll's key type is the wider int|string of any batch
                // caller; this resolver's own contract is keyed by feed id,
                // always an int.
                $feedId = (int) $feedId;
                $icons[$feedId] = mb_substr(
                    $this->iconFrom($outcome, $origins[$feedId]) ?? $origins[$feedId] . '/favicon.ico',
                    0,
                    self::URL_MAX,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Favicon batch fetch failed', ['exception' => $e]);
        }

        foreach ($origins as $feedId => $origin) {
            $icons[$feedId] ??= mb_substr($origin . '/favicon.ico', 0, self::URL_MAX);
        }

        return $icons;
    }

    private function iconFrom(FetchOutcome $outcome, string $origin): ?string
    {
        $failure = $outcome->failure();
        if (null !== $failure) {
            $this->logger->info('Favicon fetch failed for {origin}', ['origin' => $origin, 'exception' => $failure]);

            return null;
        }

        $response = $outcome->responseOrThrow();
        $body = $response->body ?? '';

        return '' === trim($body) ? null : $this->pickIcon($body, $response->finalUrl);
    }

    /** The best https icon a page's <link> tags advertise, or null. */
    private function pickIcon(string $html, string $baseUrl): ?string
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never let the parser dereference external entities.
        $dom->loadHTML($html, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $best = null;
        $bestSize = -1;
        foreach ($dom->getElementsByTagName('link') as $link) {
            // Matches "icon", "shortcut icon" and "apple-touch-icon".
            if (!str_contains(strtolower(trim($link->getAttribute('rel'))), 'icon')) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            if ('' === $href) {
                continue;
            }

            $resolved = UrlResolver::resolve($baseUrl, $href);
            // The app is https, so a http icon would be mixed-content blocked.
            if (!str_starts_with($resolved, 'https://')) {
                continue;
            }

            $size = self::largestSize($link->getAttribute('sizes'));
            if ($size > $bestSize) {
                $bestSize = $size;
                $best = $resolved;
            }
        }

        return $best;
    }

    /**
     * The largest edge declared in a `sizes` attribute ("32x32 16x16" -> 32).
     * A scalable icon ("any", typically SVG) outranks any raster size; an absent
     * or unparseable attribute scores 0 so a sized icon always wins over it.
     */
    private static function largestSize(string $sizes): int
    {
        $sizes = strtolower(trim($sizes));
        if ('' === $sizes) {
            return 0;
        }
        if (str_contains($sizes, 'any')) {
            return \PHP_INT_MAX;
        }

        $largest = 0;
        foreach (preg_split('/\s+/', $sizes) ?: [] as $token) {
            if (1 === preg_match('/^(\d+)x\d+$/', $token, $m)) {
                $largest = max($largest, (int) $m[1]);
            }
        }

        return $largest;
    }

    /** "https://host" derived from any URL, or null when it carries no host. */
    private static function httpsOrigin(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return null;
        }

        return 'https://' . $host;
    }
}
