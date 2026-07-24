<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Psr\Log\LoggerInterface;

/**
 * Best-effort favicon resolution for a feed's site. Fetches the site homepage
 * through the SSRF-guarded fetcher, parses its <link rel="...icon..."> tags and
 * returns the largest https icon, falling back to the /favicon.ico convention.
 * Never throws: a favicon is a nicety, so any failure degrades to the fallback
 * (or null) rather than disturbing the refresh that asked for it.
 */
final readonly class FaviconResolver
{
    private const URL_MAX = 2048;

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve a favicon URL for the site behind $baseUrl (a feed's siteUrl or,
     * failing that, its feed URL). Returns an https URL, or null when $baseUrl
     * carries no host to derive one from.
     */
    public function resolve(?string $baseUrl): ?string
    {
        if (null === $baseUrl) {
            return null;
        }
        $origin = self::httpsOrigin($baseUrl);
        if (null === $origin) {
            return null;
        }

        $icon = $this->fromHomepage($origin);

        return mb_substr($icon ?? $origin . '/favicon.ico', 0, self::URL_MAX);
    }

    private function fromHomepage(string $origin): ?string
    {
        try {
            $response = $this->fetcher->fetch($origin);
        } catch (\Throwable $e) {
            $this->logger->info('Favicon fetch failed for {origin}', ['origin' => $origin, 'exception' => $e]);

            return null;
        }

        $body = $response->body ?? '';
        if ('' === trim($body)) {
            return null;
        }

        return $this->pickIcon($body, $response->finalUrl);
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
